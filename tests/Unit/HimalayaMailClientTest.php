<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\HimalayaMailClient;
use Tests\TestCase;

/**
 * Pins the interim retry-net contract (incident 2026-05-16):
 *   - a clean read succeeds in one attempt,
 *   - a first timeout followed by a success recovers (this is the whole
 *     point — the himalaya hang is intermittent, so one retry rescues
 *     most missed Viator/GYG bookings),
 *   - two failures in a row return a structured ok=false WITHOUT throwing
 *     (so the caller's per-message loop continues — one poison message
 *     must never freeze the whole ingestion run),
 *   - timed_out is reported truthfully so GYG's body-fail-still-store
 *     contract keeps working downstream.
 *
 * runRead() is the himalaya-process seam; the testable subclass scripts
 * its per-attempt outcomes so no process is ever spawned.
 */
final class HimalayaMailClientTest extends TestCase
{
    /** @param list<array{ok:bool,output:?string,timed_out:bool,exit:?int}> $script */
    private function client(array $script, int $maxAttempts = 2): HimalayaMailClient
    {
        return new class($script, $maxAttempts) extends HimalayaMailClient
        {
            /** @var list<array{ok:bool,output:?string,timed_out:bool,exit:?int}> */
            public array $script;

            public int $calls = 0;

            public function __construct(array $script, int $maxAttempts)
            {
                parent::__construct(timeoutSeconds: 60, maxAttempts: $maxAttempts);
                $this->script = $script;
            }

            protected function runRead(array $args, int $timeout): array
            {
                $this->calls++;

                return array_shift($this->script)
                    ?? ['ok' => false, 'output' => null, 'timed_out' => true, 'exit' => null];
            }
        };
    }

    public function test_clean_read_succeeds_in_one_attempt(): void
    {
        $c = $this->client([
            ['ok' => true, 'output' => 'BODY', 'timed_out' => false, 'exit' => 0],
        ]);

        $r = $c->readMessage('111', 'gmail');

        $this->assertTrue($r['ok']);
        $this->assertSame('BODY', $r['output']);
        $this->assertSame(1, $r['attempts']);
        $this->assertSame(1, $c->calls);
    }

    public function test_first_timeout_then_success_recovers(): void
    {
        $c = $this->client([
            ['ok' => false, 'output' => null, 'timed_out' => true, 'exit' => null],
            ['ok' => true,  'output' => 'BODY', 'timed_out' => false, 'exit' => 0],
        ]);

        $r = $c->readMessage('222', 'gmail');

        $this->assertTrue($r['ok']);
        $this->assertSame('BODY', $r['output']);
        $this->assertSame(2, $r['attempts']);
        $this->assertSame(2, $c->calls);
    }

    public function test_repeated_timeout_returns_structured_failure_without_throwing(): void
    {
        $c = $this->client([
            ['ok' => false, 'output' => null, 'timed_out' => true, 'exit' => null],
            ['ok' => false, 'output' => null, 'timed_out' => true, 'exit' => null],
        ]);

        // The key guarantee: NO exception — caller loop must keep going.
        $r = $c->readMessage('333', 'gmail');

        $this->assertFalse($r['ok']);
        $this->assertTrue($r['timed_out']);
        $this->assertNull($r['output']);
        $this->assertSame(2, $r['attempts']);
        $this->assertSame(2, $c->calls, 'must not retry beyond maxAttempts');
    }

    public function test_non_timeout_failure_then_success_also_recovers(): void
    {
        $c = $this->client([
            ['ok' => false, 'output' => null, 'timed_out' => false, 'exit' => 1],
            ['ok' => true,  'output' => 'BODY', 'timed_out' => false, 'exit' => 0],
        ]);

        $r = $c->readMessage('444', 'gmail');

        $this->assertTrue($r['ok']);
        $this->assertSame(2, $r['attempts']);
    }

    public function test_exhausted_non_timeout_failure_reports_not_timed_out(): void
    {
        $c = $this->client([
            ['ok' => false, 'output' => null, 'timed_out' => false, 'exit' => 1],
            ['ok' => false, 'output' => null, 'timed_out' => false, 'exit' => 1],
        ]);

        $r = $c->readMessage('555', 'gmail');

        $this->assertFalse($r['ok']);
        $this->assertFalse($r['timed_out'], 'process error must not masquerade as a timeout');
    }
}
