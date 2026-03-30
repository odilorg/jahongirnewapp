<?php

namespace App\Services\Fx;

use App\Enums\Beds24SyncStatus;
use App\Models\Beds24PaymentSync;
use App\Models\CashTransaction;
use Illuminate\Support\Str;

/**
 * Creates Beds24PaymentSync rows and handles push-state transitions.
 * The actual HTTP call to Beds24 is made by Beds24PaymentSyncJob.
 */
class Beds24PaymentSyncService
{
    /**
     * Create a pending sync row for a freshly recorded cash transaction.
     * Called inside the BotPaymentService atomic transaction (before commit).
     *
     * @param  CashTransaction  $transaction
     * @param  float            $usdEquivalent  USD amount to push to Beds24
     * @return Beds24PaymentSync
     */
    public function createPending(CashTransaction $transaction, float $usdEquivalent): Beds24PaymentSync
    {
        return Beds24PaymentSync::create([
            'cash_transaction_id' => $transaction->id,
            'beds24_booking_id'   => $transaction->beds24_booking_id,
            'local_reference'     => (string) Str::uuid(),
            'amount_usd'          => $usdEquivalent,
            'status'              => Beds24SyncStatus::Pending->value,
        ]);
    }

    /**
     * Transition to "pushing" at the start of a job attempt.
     * Returns false if the row is already in a terminal state (skip the push).
     */
    public function markPushing(Beds24PaymentSync $sync): bool
    {
        if ($sync->status->isTerminal()) {
            return false;
        }

        $sync->update([
            'status'       => Beds24SyncStatus::Pushing->value,
            'last_push_at' => now(),
        ]);

        return true;
    }

    /**
     * Record a successful push response from Beds24.
     *
     * @param  string  $beds24PaymentId  ID returned by the Beds24 API
     */
    public function markPushed(Beds24PaymentSync $sync, string $beds24PaymentId): void
    {
        $sync->update([
            'status'            => Beds24SyncStatus::Pushed->value,
            'beds24_payment_id' => $beds24PaymentId,
            'last_error'        => null,
        ]);

        // Back-link the sync ID onto the transaction
        $sync->cashTransaction()->update([
            'beds24_payment_sync_id' => $sync->id,
            'beds24_payment_ref'     => $sync->local_reference,
        ]);
    }

    /**
     * Record a failed push attempt.
     *
     * @param  bool  $exhausted  True when max retries reached — set status to Failed
     */
    public function markFailed(Beds24PaymentSync $sync, string $error, bool $exhausted = false): void
    {
        $sync->increment('push_attempts');
        $sync->update([
            'status'     => $exhausted
                            ? Beds24SyncStatus::Failed->value
                            : Beds24SyncStatus::Pending->value, // will be retried
            'last_error' => $error,
        ]);
    }

    /**
     * Confirm via incoming webhook — idempotent, called by WebhookReconciliationService.
     */
    public function markConfirmed(Beds24PaymentSync $sync, array $rawPayload): void
    {
        if ($sync->status === Beds24SyncStatus::Confirmed) {
            return; // Already confirmed — webhook delivered twice, safe to ignore
        }

        $sync->update([
            'status'               => Beds24SyncStatus::Confirmed->value,
            'webhook_confirmed_at' => now(),
            'webhook_raw_payload'  => $rawPayload,
        ]);
    }

    /**
     * Mark as skipped (e.g. booking was cancelled in Beds24 before push).
     */
    public function markSkipped(Beds24PaymentSync $sync, string $reason): void
    {
        $sync->update([
            'status'     => Beds24SyncStatus::Skipped->value,
            'last_error' => $reason,
        ]);
    }

    /**
     * Find a sync row by its local_reference UUID.
     * Returns null if not found (webhook for an unknown reference — ignore).
     */
    public function findByReference(string $localReference): ?Beds24PaymentSync
    {
        return Beds24PaymentSync::where('local_reference', $localReference)->first();
    }
}
