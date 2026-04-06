<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Beds24PaymentSyncJob;
use App\Models\Beds24PaymentSync;
use App\Models\CashTransaction;
use App\Services\Fx\Beds24PaymentSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Defensive repair for cash_transactions that should have a Beds24 payment sync
 * row but don't.
 *
 * When this can happen:
 *   - Technically impossible in normal operation: BotPaymentService creates the
 *     sync row atomically inside the same DB transaction as the cash_transaction.
 *     If the sync row creation fails, the whole transaction rolls back.
 *   - But it CAN happen for:
 *     * Historical payments recorded before the sync system was deployed
 *     * Payments made while the feature flag beds24_auto_push_payment was disabled
 *       (sync row still created, but push_attempts=0 and job never dispatched)
 *     * Manual/admin payments that bypass BotPaymentService
 *     * Edge cases during system migration or import
 *
 * Detection:
 *   cash_transactions WHERE beds24_booking_id IS NOT NULL
 *                      AND beds24_payment_sync_id IS NULL
 *
 * Behavior:
 *   1. Create a new Beds24PaymentSync row in pending state.
 *   2. Dispatch Beds24PaymentSyncJob for the new row.
 *   3. Link the sync row back to the transaction (beds24_payment_sync_id).
 *
 * Idempotent: the UNIQUE constraint on beds24_payment_syncs.cash_transaction_id
 * prevents duplicate rows. On re-run, already-linked transactions are skipped.
 *
 * Safe to run repeatedly. Supports --dry-run for inspection.
 * --since-days limits the lookback window (default: 90 days) to avoid
 * accidentally picking up very old historical payments.
 *
 * Scheduling recommendation: daily (pairs with beds24:repair-failed-syncs).
 */
class RepairMissingBeds24Syncs extends Command
{
    protected $signature = 'beds24:repair-missing-syncs
                            {--since-days=90 : Only scan transactions from the last N days}
                            {--dry-run       : Report what would be created without dispatching}';

    protected $description = 'Create and dispatch Beds24 payment sync jobs for cash transactions with no sync record';

    public function handle(Beds24PaymentSyncService $syncService): int
    {
        $sinceDays = (int) $this->option('since-days');
        $dryRun    = (bool) $this->option('dry-run');

        // ── Find cash transactions with no sync row ───────────────────────────
        $missing = CashTransaction::whereNotNull('beds24_booking_id')
            ->whereNull('beds24_payment_sync_id')
            ->where('created_at', '>=', now()->subDays($sinceDays))
            ->get();

        // Belt-and-suspenders: also exclude any that have a sync row via the
        // cash_transaction_id FK on beds24_payment_syncs (handles the case where
        // beds24_payment_sync_id column was not back-filled but sync row exists)
        $missingIds = $missing->pluck('id')->toArray();

        if (! empty($missingIds)) {
            $alreadySynced = Beds24PaymentSync::whereIn('cash_transaction_id', $missingIds)
                ->pluck('cash_transaction_id')
                ->flip();

            $missing = $missing->reject(fn ($tx) => $alreadySynced->has($tx->id));
        }

        if ($missing->isEmpty()) {
            $this->info('beds24:repair-missing-syncs: no missing syncs found.');
            return self::SUCCESS;
        }

        $this->info("beds24:repair-missing-syncs: found {$missing->count()} cash transaction(s) with no sync row (last {$sinceDays} days).");

        if ($dryRun) {
            $this->warn('[dry-run] Would create sync rows for transaction IDs: ' . $missing->pluck('id')->implode(', '));
            return self::SUCCESS;
        }

        // ── Create sync rows and dispatch jobs ────────────────────────────────
        $created    = 0;
        $dispatched = 0;
        $errors     = [];

        foreach ($missing as $transaction) {
            try {
                // Use usd_equivalent_paid as the amount to push
                $usdAmount = (float) ($transaction->usd_equivalent_paid ?? 0.0);

                if ($usdAmount <= 0) {
                    $errors[] = "tx#{$transaction->id}: usd_equivalent_paid is zero or null — skipping";
                    Log::warning('beds24:repair-missing-syncs: skipping zero-amount transaction', [
                        'cash_transaction_id' => $transaction->id,
                        'beds24_booking_id'   => $transaction->beds24_booking_id,
                    ]);
                    continue;
                }

                $syncRow = $syncService->createPending($transaction, $usdAmount);
                $created++;

                // Back-link the sync row to the transaction
                $transaction->update(['beds24_payment_sync_id' => $syncRow->id]);

                Beds24PaymentSyncJob::dispatch($syncRow->id);
                $dispatched++;

                Log::info('beds24:repair-missing-syncs: created sync row and dispatched job', [
                    'sync_id'             => $syncRow->id,
                    'cash_transaction_id' => $transaction->id,
                    'beds24_booking_id'   => $transaction->beds24_booking_id,
                    'amount_usd'          => $usdAmount,
                ]);
            } catch (\Throwable $e) {
                $errors[] = "tx#{$transaction->id}: {$e->getMessage()}";
                Log::error('beds24:repair-missing-syncs: error creating sync row', [
                    'cash_transaction_id' => $transaction->id,
                    'error'               => $e->getMessage(),
                ]);
            }
        }

        // ── Structured log ────────────────────────────────────────────────────
        Log::info('beds24:repair-missing-syncs: completed', [
            'scanned'        => $missing->count(),
            'created'        => $created,
            'dispatched'     => $dispatched,
            'errors'         => count($errors),
            'since_days'     => $sinceDays,
        ]);

        // ── Output summary ────────────────────────────────────────────────────
        $this->info("  sync rows created: {$created}");
        $this->info("  jobs dispatched:   {$dispatched}");

        if (! empty($errors)) {
            foreach ($errors as $err) {
                $this->error($err);
            }
            Log::error('beds24:repair-missing-syncs: errors encountered', ['errors' => $errors]);
        }

        return empty($errors) ? self::SUCCESS : self::FAILURE;
    }
}
