<?php

declare(strict_types=1);

namespace Tests\Feature\Viator;

use App\Console\Commands\ViatorFetchEmails;
use App\Models\ViatorInboundEmail;
use App\Services\HimalayaMailClient;
use App\Services\Viator\ViatorEmailParser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

/**
 * Pins the Viator side of the retry-net (incident 2026-05-16):
 *   - a readable envelope is stored normally,
 *   - an UNREADABLE envelope (himalaya hung even after the retry) does
 *     NOT abort the run — it is recorded as a visible STATUS_FAILED row
 *     for manual review, and the loop CONTINUES to the next envelope,
 *   - --dry-run never writes (not even the failed stub),
 *   - the failed stub is idempotent (re-run does not duplicate it).
 *
 * The himalaya process + the IMAP envelope list are both faked, so the
 * test is deterministic and never touches the network.
 */
final class ViatorFetchEmailsRetryNetTest extends TestCase
{
    use DatabaseTransactions;

    private function fakeClient(array $map): HimalayaMailClient
    {
        return new class($map) extends HimalayaMailClient
        {
            public function __construct(private array $map)
            {
                parent::__construct();
            }

            public function readMessage(string $envelopeId, ?string $account = null, array $extraArgs = []): array
            {
                $r = $this->map[$envelopeId] ?? ['ok' => false, 'output' => null, 'timed_out' => true];

                return $r + ['attempts' => ($r['ok'] ?? false) ? 1 : 2, 'exit' => ($r['ok'] ?? false) ? 0 : null];
            }
        };
    }

    private function command(array $envelopes, HimalayaMailClient $client): ViatorFetchEmails
    {
        $cmd = new class(new ViatorEmailParser, $client) extends ViatorFetchEmails
        {
            public array $stubEnvelopes = [];

            protected function listViatorEnvelopes(int $limit): array
            {
                return $this->stubEnvelopes;
            }
        };
        $cmd->stubEnvelopes = $envelopes;
        $cmd->setLaravel($this->app);

        return $cmd;
    }

    private function runCmd(ViatorFetchEmails $cmd, bool $dryRun = false): void
    {
        $cmd->run(
            new ArrayInput($dryRun ? ['--dry-run' => true] : []),
            new NullOutput,
        );
    }

    public function test_unreadable_envelope_is_flagged_and_does_not_block_the_rest(): void
    {
        $good = file_get_contents(base_path('tests/Fixtures/Viator/new_daytour.txt'));

        $envelopes = [
            ['id' => 'A1', 'subject' => 'New Booking for Wed, Apr 09, 2025 (#BR-1245960303)'],
            ['id' => 'B2', 'subject' => 'New Booking for Fri, Jun 06, 2025 (#BR-2000000002)'],
            ['id' => 'C3', 'subject' => 'New Booking for Sat, Jul 07, 2025 (#BR-3000000003)'],
        ];

        $client = $this->fakeClient([
            'A1' => ['ok' => true,  'output' => $good, 'timed_out' => false],
            'B2' => ['ok' => false, 'output' => null,  'timed_out' => true],   // hung even after retry
            'C3' => ['ok' => true,  'output' => $good, 'timed_out' => false],
        ]);

        $this->runCmd($this->command($envelopes, $client));

        // All three accounted for — B did NOT abort the loop.
        $this->assertSame(3, ViatorInboundEmail::count());

        $failed = ViatorInboundEmail::where('processing_status', ViatorInboundEmail::STATUS_FAILED)->get();
        $this->assertCount(1, $failed, 'exactly the unreadable envelope is flagged');
        $this->assertStringContainsString('BR-2000000002', $failed->first()->subject_raw);
        $this->assertSame('', $failed->first()->raw_body);
        $this->assertSame(ViatorInboundEmail::TYPE_UNKNOWN, $failed->first()->email_type);
        $this->assertStringContainsString('manual review', (string) $failed->first()->error_message);

        // The envelope AFTER the failed one was still processed (continue worked).
        $this->assertSame(
            2,
            ViatorInboundEmail::where('processing_status', '!=', ViatorInboundEmail::STATUS_FAILED)->count(),
            'the readable envelopes before AND after the failure were stored',
        );
    }

    public function test_failed_stub_is_idempotent_across_runs(): void
    {
        $envelopes = [['id' => 'B2', 'subject' => 'New Booking for Fri, Jun 06, 2025 (#BR-2000000002)']];
        $client = $this->fakeClient(['B2' => ['ok' => false, 'output' => null, 'timed_out' => true]]);

        $this->runCmd($this->command($envelopes, $client));
        $this->runCmd($this->command($envelopes, $client)); // second run, same envelope

        $this->assertSame(1, ViatorInboundEmail::count(), 'failed stub must not duplicate on re-run');
    }

    public function test_dry_run_writes_nothing_even_for_unreadable(): void
    {
        $envelopes = [['id' => 'B2', 'subject' => 'New Booking for Fri, Jun 06, 2025 (#BR-2000000002)']];
        $client = $this->fakeClient(['B2' => ['ok' => false, 'output' => null, 'timed_out' => true]]);

        $this->runCmd($this->command($envelopes, $client), dryRun: true);

        $this->assertSame(0, ViatorInboundEmail::count());
    }
}
