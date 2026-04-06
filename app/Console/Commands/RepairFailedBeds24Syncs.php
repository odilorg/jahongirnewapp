<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Beds24SyncStatus;
use App\Jobs\Beds24PaymentSyncJob;
use App\Models\Beds24PaymentSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Retry Beds24PaymentSync rows that have permanently failed (status = 'failed').
 *
 * Context:
 *   Beds24PaymentSyncJob exhausts its 3 built-in retries (60s backoff each).
 *   When fully exhausted, markFailed() sets status to 'failed'.
 *   fx:surface-failed-syncs surfaces them as alerts, but does NOT retry.
 *
 * This command:
 *   1. Finds 'failed' rows with push_attempts < --max-attempts (default 9).
 *      (9 = 3 repair rounds × 3 job attempts each — configurable.)
 *   2. Resets them to 'pending' so the job's terminal guard doesn't block the run.
 *   3. Re-dispatches Beds24PaymentSyncJob.
 *   4. Escalates rows at or beyond --max-attempts: logs a warning and leaves
 *      them as 'failed' — they are visible in fx:surface-failed-syncs and
 *      require manual ops decision.
 *
 * The push_attempts column on beds24_payment_syncs tracks cumulative API call
 * attempts across all dispatch rounds (job + repair). This is the retry budget.
 *
 * Idempotent: rows already in a terminal state are skipped without mutation.
 * Safe to run repeatedly.
 *
 * Scheduling recommendation: daily (aggressive retrying of failed syncs is
 * rarely productive; nightly retry + nightly alert is the right cadence).
 */
class RepairFailedBeds24Syncs extends Command
{
    protected $signature = 'beds24:repair-failed-syncs
                            {--max-attempts=9 : Escalate (not retry) rows with this many push_attempts or more}
                            {--dry-run        : Report what would be retried without dispatching jobs}';

    protected $description = 'Retry permanently-failed Beds24 payment syncs within the push-attempt budget';

    public function handle(): int
    {
        $maxAttempts = (int) $this->option('max-attempts');
        $dryRun      = (bool) $this->option('dry-run');

        $failed = Beds24PaymentSync::where('status', Beds24SyncStatus::Failed->value)->get();

        if ($failed->isEmpty()) {
            $this->info('beds24:repair-failed-syncs: no failed syncs found.');
            return self::SUCCESS;
        }

        // ── Classify ──────────────────────────────────────────────────────────
        $retryable  = $failed->filter(fn ($s) => $s->push_attempts < $maxAttempts);
        $escalated  = $failed->filter(fn ($s) => $s->push_attempts >= $maxAttempts);

        $this->info("beds24:repair-failed-syncs: found {$failed->count()} failed sync(s).");
        $this->info("  retryable (push_attempts < {$maxAttempts}): {$retryable->count()}");
        $this->info("  escalated (push_attempts >= {$maxAttempts}): {$escalated->count()}");

        // ── Dry-run ───────────────────────────────────────────────────────────
        if ($dryRun) {
            if ($retryable->isNotEmpty()) {
                $this->warn('[dry-run] Would retry sync IDs: ' . $retryable->pluck('id')->implode(', '));
            }
            if ($escalated->isNotEmpty()) {
                $this->warn('[dry-run] Would escalate sync IDs: ' . $escalated->pluck('id')->implode(', '));
            }
            return self::SUCCESS;
        }

        // ── Retry retryable rows ──────────────────────────────────────────────
        $dispatched = 0;
        $dispatchErrors = [];

        foreach ($retryable as $sync) {
            try {
                // Reset to pending so Beds24PaymentSyncJob's terminal guard allows the run
                $sync->update(['status' => Beds24SyncStatus::Pending->value]);
                Beds24PaymentSyncJob::dispatch($sync->id);
                $dispatched++;
            } catch (\Throwable $e) {
                $dispatchErrors[] = "sync#{$sync->id}: {$e->getMessage()}";
                // Restore failed status so the row doesn't silently disappear
                $sync->update(['status' => Beds24SyncStatus::Failed->value]);
            }
        }

        // ── Log escalations ───────────────────────────────────────────────────
        foreach ($escalated as $sync) {
            Log::warning('beds24:repair-failed-syncs: escalated — max attempts reached', [
                'sync_id'           => $sync->id,
                'beds24_booking_id' => $sync->beds24_booking_id,
                'cash_tx_id'        => $sync->cash_transaction_id,
                'push_attempts'     => $sync->push_attempts,
                'last_error'        => $sync->last_error,
                'amount_usd'        => $sync->amount_usd,
                'reason'            => "push_attempts ({$sync->push_attempts}) >= max-attempts ({$maxAttempts})",
            ]);
        }

        // ── Structured log ────────────────────────────────────────────────────
        Log::info('beds24:repair-failed-syncs: completed', [
            'total_failed'    => $failed->count(),
            'retried'         => $dispatched,
            'escalated'       => $escalated->count(),
            'dispatch_errors' => count($dispatchErrors),
            'retried_ids'     => $retryable->pluck('id')->toArray(),
            'escalated_ids'   => $escalated->pluck('id')->toArray(),
        ]);

        // ── Output summary ────────────────────────────────────────────────────
        if ($dispatched > 0) {
            $this->info("  dispatched retry jobs: {$dispatched}");
        }
        if ($escalated->isNotEmpty()) {
            $this->error("  escalated (manual review required): {$escalated->count()}");
        }
        if (! empty($dispatchErrors)) {
            foreach ($dispatchErrors as $err) {
                $this->error($err);
            }
        }

        return empty($dispatchErrors) ? self::SUCCESS : self::FAILURE;
    }
}
