<?php

namespace App\Console\Commands;

use App\Enums\Beds24SyncStatus;
use App\Jobs\Beds24PaymentSyncJob;
use App\Models\Beds24PaymentSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Repair Beds24PaymentSync rows that are stuck in non-terminal states.
 *
 * Two cases:
 *
 *   1. status = 'pending', older than --pending-after minutes
 *      The cash transaction was recorded and the Beds24PaymentSync row was
 *      created, but DB::afterCommit() never dispatched the job (queue worker
 *      was down, or the process was killed after DB commit but before dispatch).
 *      Fix: re-dispatch Beds24PaymentSyncJob.
 *
 *   2. status = 'pushing', older than --pushing-after minutes
 *      The job started (markPushing() was called) but the worker was killed
 *      mid-run (server restart, OOM, etc.). The row is stuck in 'pushing'
 *      indefinitely — markPushing() will reject new attempts on it.
 *      Fix: reset to 'pending' so the next dispatch can proceed, then re-dispatch.
 *
 * Permanently failed rows (status = 'failed') are NOT touched — they appear in
 * fx:nightly-report and require ops decision before being retried.
 *
 * Safe to run frequently. Supports --dry-run for inspection.
 *
 * Scheduled: every 30 minutes (see Kernel.php)
 */
class RepairStuckBeds24Syncs extends Command
{
    protected $signature = 'fx:repair-stuck-syncs
                            {--pending-after=15 : Treat pending rows older than N minutes as stuck}
                            {--pushing-after=10 : Treat pushing rows older than N minutes as stuck}
                            {--dry-run : Report what would be repaired without dispatching jobs}';

    protected $description = 'Re-dispatch Beds24PaymentSyncJob for sync rows stuck in pending or pushing state';

    public function handle(): int
    {
        $pendingAfter = (int) $this->option('pending-after');
        $pushingAfter = (int) $this->option('pushing-after');
        $dryRun       = (bool) $this->option('dry-run');

        // ── 1. Stuck-pending rows ─────────────────────────────────────────────
        $stuckPending = Beds24PaymentSync::where('status', Beds24SyncStatus::Pending->value)
            ->where('created_at', '<=', now()->subMinutes($pendingAfter))
            ->get();

        // ── 2. Stuck-pushing rows ─────────────────────────────────────────────
        $stuckPushing = Beds24PaymentSync::where('status', Beds24SyncStatus::Pushing->value)
            ->where('last_push_at', '<=', now()->subMinutes($pushingAfter))
            ->get();

        $total = $stuckPending->count() + $stuckPushing->count();

        if ($total === 0) {
            $this->info('fx:repair-stuck-syncs: nothing to repair.');
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn("[dry-run] Would repair {$total} stuck sync row(s):");
            $this->warn("  pending ({$stuckPending->count()}): " . $stuckPending->pluck('id')->implode(', '));
            $this->warn("  pushing ({$stuckPushing->count()}): " . $stuckPushing->pluck('id')->implode(', '));
            return self::SUCCESS;
        }

        $dispatched = 0;
        $errors     = [];

        // Re-dispatch pending rows — job is idempotent, safe to re-queue
        foreach ($stuckPending as $sync) {
            try {
                Beds24PaymentSyncJob::dispatch($sync->id);
                $dispatched++;
            } catch (\Throwable $e) {
                $errors[] = "sync#{$sync->id}: {$e->getMessage()}";
            }
        }

        // Reset pushing → pending, then dispatch
        foreach ($stuckPushing as $sync) {
            try {
                $sync->update(['status' => Beds24SyncStatus::Pending->value]);
                Beds24PaymentSyncJob::dispatch($sync->id);
                $dispatched++;
            } catch (\Throwable $e) {
                $errors[] = "sync#{$sync->id}: {$e->getMessage()}";
            }
        }

        Log::info('fx:repair-stuck-syncs: dispatched jobs', [
            'pending_count'  => $stuckPending->count(),
            'pushing_count'  => $stuckPushing->count(),
            'dispatched'     => $dispatched,
            'errors'         => count($errors),
            'pending_ids'    => $stuckPending->pluck('id')->toArray(),
            'pushing_ids'    => $stuckPushing->pluck('id')->toArray(),
        ]);

        if (! empty($errors)) {
            foreach ($errors as $err) {
                $this->error($err);
            }
            Log::error('fx:repair-stuck-syncs: dispatch errors', ['errors' => $errors]);
        }

        $this->info("fx:repair-stuck-syncs: dispatched {$dispatched} job(s) ({$stuckPending->count()} pending, {$stuckPushing->count()} pushing-reset).");

        return empty($errors) ? self::SUCCESS : self::FAILURE;
    }
}
