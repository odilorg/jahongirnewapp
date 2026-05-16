<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Infrastructure adapter for the `himalaya` IMAP CLI (Gmail inbound mail).
 *
 * WHY THIS EXISTS (incident 2026-05-16):
 * himalaya v1.2.0 `message read` intermittently HANGS >60s on certain
 * Gmail FETCH responses (its imap-codec "Rectified missing `text`" path).
 * Each read is a fresh process = fresh IMAP LOGIN, so the hang is per
 * message and partially intermittent — the SAME envelope can hang on one
 * run and read in ~1s on the next. Viator booking ingestion was silently
 * degraded from ~2026-05-14 because the new-booking emails kept landing
 * on a single hung attempt.
 *
 * This adapter is the INTERIM retry-net (durable replacement = a
 * persistent PHP IMAP client, planned separately). Contract:
 *   - bounded per-attempt timeout (default 60s, matches the PHP ceiling
 *     that was firing in production),
 *   - on timeout/failure: kill + retry the SAME envelope once,
 *   - NEVER throw on timeout/failure — return a structured result so the
 *     caller's per-message loop continues. One poison/hanging email must
 *     not freeze the whole Viator/GYG run.
 *   - structured per-attempt logging (envelope_id, attempt, rc, wall, bytes).
 *
 * The real himalaya invocation is isolated in runRead() so tests can
 * script timeout/success sequences without spawning a process.
 */
class HimalayaMailClient
{
    public function __construct(
        private readonly int $timeoutSeconds = 60,
        private readonly int $maxAttempts = 2, // 1 initial + 1 retry
    ) {}

    /**
     * Read one message by envelope id, with bounded retry.
     *
     * @param  string  $envelopeId  himalaya envelope id (IMAP seq no.)
     * @param  string|null  $account  himalaya --account; null = default account
     * @param  list<string>  $extraArgs  e.g. ['--preview', '--header', 'Message-ID']
     * @return array{ok: bool, output: string|null, timed_out: bool, attempts: int, exit: int|null}
     */
    public function readMessage(string $envelopeId, ?string $account = null, array $extraArgs = []): array
    {
        $args = ['himalaya', 'message', 'read'];
        if ($account !== null) {
            $args[] = '--account';
            $args[] = $account;
        }
        foreach ($extraArgs as $a) {
            $args[] = $a;
        }
        $args[] = $envelopeId;

        $timedOut = false;
        $exit = null;

        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            $started = microtime(true);
            $r = $this->runRead($args, $this->timeoutSeconds);
            $wall = round(microtime(true) - $started, 1);
            $bytes = $r['output'] !== null ? strlen($r['output']) : 0;
            $timedOut = $r['timed_out'];
            $exit = $r['exit'];

            Log::info('HimalayaMailClient: read attempt', [
                'envelope_id' => $envelopeId,
                'account' => $account,
                'attempt' => $attempt,
                'max' => $this->maxAttempts,
                'rc' => $exit,
                'wall_s' => $wall,
                'bytes' => $bytes,
                'timed_out' => $timedOut,
                'ok' => $r['ok'],
            ]);

            if ($r['ok']) {
                return [
                    'ok' => true,
                    'output' => $r['output'],
                    'timed_out' => false,
                    'attempts' => $attempt,
                    'exit' => $exit,
                ];
            }
            // failed or timed out → loop retries until maxAttempts
        }

        Log::warning('HimalayaMailClient: read exhausted (unreadable after retry)', [
            'envelope_id' => $envelopeId,
            'account' => $account,
            'attempts' => $this->maxAttempts,
            'timed_out' => $timedOut,
            'rc' => $exit,
        ]);

        return [
            'ok' => false,
            'output' => null,
            'timed_out' => $timedOut,
            'attempts' => $this->maxAttempts,
            'exit' => $exit,
        ];
    }

    /**
     * Seam: the real himalaya process invocation. Overridden in tests to
     * script timeout/success sequences without spawning a process.
     *
     * @param  list<string>  $args
     * @return array{ok: bool, output: string|null, timed_out: bool, exit: int|null}
     */
    protected function runRead(array $args, int $timeout): array
    {
        try {
            $res = Process::timeout($timeout)->run($args);
        } catch (ProcessTimedOutException) {
            return ['ok' => false, 'output' => null, 'timed_out' => true, 'exit' => null];
        }

        return [
            'ok' => $res->successful(),
            'output' => $res->successful() ? $res->output() : null,
            'timed_out' => false,
            'exit' => $res->exitCode(),
        ];
    }
}
