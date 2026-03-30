<?php

namespace App\Services\Fx;

use App\Enums\ManagerApprovalStatus;
use App\Exceptions\Fx\ManagerApprovalAlreadyUsedException;
use App\Exceptions\Fx\ManagerApprovalNotFoundException;
use App\Models\FxManagerApproval;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Creates and resolves manager approval requests for large-variance payments.
 */
class FxManagerApprovalService
{
    /**
     * Create a pending approval request and optionally notify the manager.
     *
     * @param  string  $beds24BookingId
     * @param  string  $botSessionId
     * @param  int     $cashierId          Staff user ID of the cashier requesting approval
     * @param  string  $currency
     * @param  float   $amountPresented    From the FX snapshot
     * @param  float   $amountProposed     What cashier wants to collect
     * @param  float   $variancePct
     * @param  int|null $managerNotifiedId  If a specific manager was pinged
     */
    public function createRequest(
        string  $beds24BookingId,
        string  $botSessionId,
        int     $cashierId,
        string  $currency,
        float   $amountPresented,
        float   $amountProposed,
        float   $variancePct,
        ?int    $managerNotifiedId = null,
    ): FxManagerApproval {
        $ttlMinutes = (int) config('fx.manager_approval_ttl_minutes', 30);

        return FxManagerApproval::create([
            'beds24_booking_id'    => $beds24BookingId,
            'bot_session_id'       => $botSessionId,
            'cashier_id'           => $cashierId,
            'manager_notified_id'  => $managerNotifiedId,
            'currency'             => $currency,
            'amount_presented'     => $amountPresented,
            'amount_proposed'      => $amountProposed,
            'variance_pct'         => $variancePct,
            'status'               => ManagerApprovalStatus::Pending->value,
            'expires_at'           => now()->addMinutes($ttlMinutes),
        ]);
    }

    /**
     * Approve a pending request. Called by the manager via Telegram.
     */
    public function approve(int $approvalId, int $managerId): FxManagerApproval
    {
        return $this->resolve($approvalId, $managerId, ManagerApprovalStatus::Approved);
    }

    /**
     * Reject a pending request with a reason.
     */
    public function reject(int $approvalId, int $managerId, string $reason): FxManagerApproval
    {
        return $this->resolve($approvalId, $managerId, ManagerApprovalStatus::Rejected, $reason);
    }

    /**
     * Expire all pending approvals whose expires_at has passed.
     * Called by the scheduled ExpireManagerApprovals console command.
     *
     * @return int  Number of rows updated
     */
    public function expireStale(): int
    {
        return FxManagerApproval::query()
            ->where('status', ManagerApprovalStatus::Pending->value)
            ->where('expires_at', '<', now())
            ->update([
                'status'      => ManagerApprovalStatus::Expired->value,
                'resolved_at' => now(),
            ]);
    }

    /**
     * Find an active (pending, non-expired) approval for a booking that can be consumed.
     *
     * @throws ManagerApprovalNotFoundException
     */
    public function findConsumable(int $approvalId, string $beds24BookingId): FxManagerApproval
    {
        $approval = FxManagerApproval::find($approvalId);

        if (! $approval
            || $approval->beds24_booking_id !== $beds24BookingId
            || ! $approval->canBeConsumed()
        ) {
            throw new ManagerApprovalNotFoundException(
                "No consumable approval found (id={$approvalId}, booking={$beds24BookingId})."
            );
        }

        return $approval;
    }

    /**
     * Mark an approval as consumed (called inside the BotPaymentService transaction).
     * Must be called within an existing DB::transaction with a FOR UPDATE lock held.
     *
     * @throws ManagerApprovalAlreadyUsedException
     */
    public function consume(FxManagerApproval $approval, int $cashTransactionId): void
    {
        if ($approval->status === ManagerApprovalStatus::Consumed) {
            throw new ManagerApprovalAlreadyUsedException(
                "Approval #{$approval->id} has already been consumed."
            );
        }

        if (! $approval->canBeConsumed()) {
            throw new ManagerApprovalNotFoundException(
                "Approval #{$approval->id} cannot be consumed (status: {$approval->status->value})."
            );
        }

        $approval->update([
            'status'                      => ManagerApprovalStatus::Consumed->value,
            'resolved_at'                 => now(),
            'used_in_cash_transaction_id' => $cashTransactionId,
        ]);
    }

    // -----------------------------------------------------------------------

    private function resolve(
        int                   $approvalId,
        int                   $managerId,
        ManagerApprovalStatus $newStatus,
        ?string               $reason = null,
    ): FxManagerApproval {
        $approval = FxManagerApproval::find($approvalId);

        if (! $approval || $approval->status !== ManagerApprovalStatus::Pending) {
            throw new ManagerApprovalNotFoundException(
                "Pending approval #{$approvalId} not found."
            );
        }

        if ($approval->isExpired()) {
            throw new ManagerApprovalNotFoundException(
                "Approval #{$approvalId} has expired."
            );
        }

        $approval->update([
            'status'           => $newStatus->value,
            'resolved_by'      => $managerId,
            'resolved_at'      => now(),
            'rejection_reason' => $reason,
        ]);

        return $approval->fresh();
    }
}
