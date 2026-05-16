<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BookingInquiry;
use App\Models\ViatorInboundEmail;
use App\Services\Viator\ViatorEmailParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Pulls Viator booking-event emails via himalaya, persists each one as
 * an immutable row in viator_inbound_emails, and runs the parser to
 * populate parsed_payload + parsed_diff.
 *
 * Idempotency: the gmail_message_id (IMAP Message-ID header) is the
 * unique key. Re-running the command repeatedly is safe — already-
 * stored messages are skipped without database writes.
 *
 * This command DOES NOT auto-create BookingInquiry rows. Apply logic
 * is intentionally separated into viator:apply-new-bookings so the
 * fetch path stays focused on email ingestion and the application
 * step can be tightened (or paused) independently.
 *
 * Configuration: relies on the existing himalaya 'gmail' account
 * already used by other inbound mail commands. No new credentials
 * required.
 */
class ViatorFetchEmails extends Command
{
    protected $signature = 'viator:fetch-emails
        {--limit=50 : Maximum messages to fetch per run}
        {--dry-run : Parse + report without persisting}';

    protected $description = 'Fetch new Viator booking emails from Gmail via himalaya';

    private const HIMALAYA_ACCOUNT = 'gmail';
    private const FROM_FILTER      = 'booking@t1.viator.com';

    public function __construct(private ViatorEmailParser $parser)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit  = (int) $this->option('limit');

        $envelopes = $this->listViatorEnvelopes($limit);
        if ($envelopes === []) {
            $this->info('No Viator booking envelopes found.');
            return self::SUCCESS;
        }

        $this->info(($dryRun ? '[DRY-RUN] ' : '') . 'Found ' . count($envelopes) . ' envelope(s) to inspect.');

        $stored = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($envelopes as $env) {
            $envelopeId = (string) $env['id'];
            try {
                $body = $this->readMessage($envelopeId);
                $messageId = $this->extractMessageId($body);

                if ($messageId === null) {
                    // No Message-ID header — fall back to a hash so we
                    // still get idempotency, just less canonical.
                    $messageId = 'sha256:' . hash('sha256', $env['subject'] . '|' . $envelopeId);
                }

                if (ViatorInboundEmail::where('gmail_message_id', $messageId)->exists()) {
                    $skipped++;
                    continue;
                }

                $parsed = $this->parser->parse((string) $env['subject'], $body);

                if ($dryRun) {
                    $this->line(sprintf(
                        '  [%s] %s · %s · BR=%s',
                        $parsed['email_type'],
                        $env['subject'],
                        $messageId,
                        $parsed['external_reference'] ?? '—',
                    ));
                    $stored++;
                    continue;
                }

                $row = ViatorInboundEmail::create([
                    'gmail_message_id'   => $messageId,
                    'from_address'       => self::FROM_FILTER,
                    'subject_raw'        => mb_substr((string) $env['subject'], 0, 500),
                    'email_type'         => $parsed['email_type'],
                    'external_reference' => $parsed['external_reference'],
                    'raw_body'           => $body,
                    'parsed_payload'     => $parsed['parsed_payload'],
                    'parsed_diff'        => $this->buildDiff($parsed),
                    'processing_status'  => $parsed['email_type'] === ViatorInboundEmail::TYPE_UNKNOWN
                        ? ViatorInboundEmail::STATUS_NEEDS_REVIEW
                        : ViatorInboundEmail::STATUS_PARSED,
                    'processed_at'       => now(),
                ]);

                $stored++;
                Log::info('ViatorFetchEmails: stored', [
                    'id'          => $row->id,
                    'message_id'  => $messageId,
                    'email_type'  => $parsed['email_type'],
                    'reference'   => $parsed['external_reference'],
                ]);
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('ViatorFetchEmails: failed on envelope', [
                    'envelope_id' => $envelopeId,
                    'error'       => $e->getMessage(),
                ]);
                $this->warn("  ✗ Envelope {$envelopeId}: " . $e->getMessage());
            }
        }

        $this->info("Done. Stored: {$stored}, Skipped (already present): {$skipped}, Failed: {$failed}");
        return self::SUCCESS;
    }

    /**
     * @return array<int, array{id: string, subject: string}>
     */
    private function listViatorEnvelopes(int $limit): array
    {
        $process = new Process([
            'himalaya', 'envelope', 'list',
            '--account', self::HIMALAYA_ACCOUNT,
            '--page-size', (string) $limit,
            '--output', 'json',
            'from "' . self::FROM_FILTER . '"',
        ]);
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException('himalaya envelope list failed: ' . $process->getErrorOutput());
        }

        $json = json_decode($process->getOutput(), true);
        if (! is_array($json)) {
            return [];
        }

        $out = [];
        foreach ($json as $env) {
            $out[] = [
                'id'      => (string) ($env['id'] ?? ''),
                'subject' => (string) ($env['subject'] ?? ''),
            ];
        }
        return array_values(array_filter($out, fn ($e) => $e['id'] !== ''));
    }

    private function readMessage(string $envelopeId): string
    {
        $process = new Process([
            'himalaya', 'message', 'read',
            '--account', self::HIMALAYA_ACCOUNT,
            $envelopeId,
        ]);
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException('himalaya message read failed: ' . $process->getErrorOutput());
        }
        return $process->getOutput();
    }

    private function extractMessageId(string $body): ?string
    {
        // himalaya 'message read' output starts with headers including
        // Message-ID. Format: "Message-ID: <abc@viator.com>"
        if (preg_match('/^Message-ID:\s*<([^>]+)>/mi', $body, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    /**
     * For amendments + cancellations, compute a {field: {old, new}}
     * diff against the existing BookingInquiry so the review queue
     * shows operators exactly what's changing without them parsing
     * raw JSON.
     *
     * Returns null on new bookings (no prior state to diff against).
     */
    private function buildDiff(array $parsed): ?array
    {
        if ($parsed['email_type'] === ViatorInboundEmail::TYPE_NEW
            || $parsed['external_reference'] === null) {
            return null;
        }

        $inquiry = BookingInquiry::where('external_reference', $parsed['external_reference'])->first();
        if (! $inquiry) {
            return null; // Diff is meaningless without a prior row.
        }

        $payload = $parsed['parsed_payload'];
        $diff = [];

        if (! empty($payload['travel_date']) && (string) $inquiry->travel_date?->toDateString() !== $payload['travel_date']) {
            $diff['travel_date'] = [
                'old' => $inquiry->travel_date?->toDateString(),
                'new' => $payload['travel_date'],
            ];
        }
        if (isset($payload['people_adults']) && (int) $inquiry->people_adults !== (int) $payload['people_adults']) {
            $diff['people_adults'] = [
                'old' => (int) $inquiry->people_adults,
                'new' => (int) $payload['people_adults'],
            ];
        }
        if (! empty($payload['hotel_pickup']) && (string) $inquiry->pickup_point !== $payload['hotel_pickup']) {
            $diff['pickup_point'] = [
                'old' => $inquiry->pickup_point,
                'new' => $payload['hotel_pickup'],
            ];
        }

        return $diff === [] ? null : $diff;
    }
}
