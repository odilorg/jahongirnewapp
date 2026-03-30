<?php

namespace App\Services;

use App\DTO\PaymentPresentation;
use App\Models\FxManagerApproval;
use Illuminate\Support\Facades\DB;

/**
 * Manages manager approval requests for cashier FX payment overrides.
 *
 * Lifecycle:
 *   request()  — creates a pending approval row, bot notifies manager
 *   resolve()  — manager approves or rejects via bot callback (lockForUpdate)
 *   consume()  — called inside recordPayment() transaction to mark approved → consumed
 *
 * The lockForUpdate in resolve() prevents two managers from approving simultaneously.
 * consume() verifies status is still 'approved' before marking consumed, ensuring
 * the same approval row cannot be used for two separate payments.
 */
class FxManagerApprovalService
{
    public const APPROVAL_TTL_MINUTES = 10;

    // -------------------------------------------------------------------------
    // Create
    // -------------------------------------------------------------------------

    /**
     * Create a pending approval request for a cashier override.
     * Expires any previous pending request for the same bot session.
     *
     * @param  int    $cashierId  Explicit — do not rely on ambient auth()
     */
    public function request(
        PaymentPresentation $p,
        int                 $cashierId,
        string              $currency,
        float               $amountProposed,
        float               $variancePct,
    ): FxManagerApproval {
        // Expire any existing pending request for this session
        FxManagerApproval::where('bot_session_id', $p->botSessionId)
            ->where('status', 'pending')
            ->update(['status' => 'expired']);

        return FxManagerApproval::create([
            'beds24_booking_id' => $p->beds24BookingId,
            'bot_session_id'    => $p->botSessionId,
            'cashier_id'        => $cashierId,
            'currency'          => $currency,
            'amount_presented'  => $p->presentedAmountFor($currency),
            'amount_proposed'   => $amountProposed,
            'variance_pct'      => $variancePct,
            'status'            => 'pending',
            'expires_at'        => now()->addMinutes(self::APPROVAL_TTL_MINUTES),
        ]);
    }

    // -------------------------------------------------------------------------
    // Resolve (manager action via bot)
    // -------------------------------------------------------------------------

    /**
     * Manager approves or rejects via Telegram callback.
     * lockForUpdate prevents two simultaneous approvals from both succeeding.
     *
     * @throws \RuntimeException  If already resolved or expired
     */
    public function resolve(
        int     $approvalId,
        int     $managerId,
        bool    $approved,
        ?string $rejectionReason = null,
    ): FxManagerApproval {
        return DB::transaction(function () use ($approvalId, $managerId, $approved, $rejectionReason) {
            $approval = FxManagerApproval::lockForUpdate()->findOrFail($approvalId);

            if ($approval->status !== 'pending') {
                throw new \RuntimeException(
                    "Approval #{$approvalId} cannot be resolved — status is '{$approval->status}'."
                );
            }

            if ($approval->isExpired()) {
                $approval->update(['status' => 'expired']);
                throw new \RuntimeException("Approval #{$approvalId} has expired.");
            }

            $approval->update([
                'status'           => $approved ? 'approved' : 'rejected',
                'resolved_by'      => $managerId,
                'resolved_at'      => now(),
                'rejection_reason' => $rejectionReason,
            ]);

            return $approval->fresh();
        });
    }

    // -------------------------------------------------------------------------
    // Consume (called inside recordPayment() DB transaction)
    // -------------------------------------------------------------------------

    /**
     * Mark an approval as consumed and link it to the payment that used it.
     * MUST be called inside the same DB::transaction as CashTransaction::create().
     *
     * @throws \RuntimeException  If approval is no longer in 'approved' state
     */
    public function consume(FxManagerApproval $approval, int $cashTransactionId): void
    {
        // Re-lock and re-check inside the payment transaction
        $fresh = FxManagerApproval::lockForUpdate()->findOrFail($approval->id);

        if ($fresh->status !== 'approved') {
            throw new \RuntimeException(
                "Manager approval #{$approval->id} cannot be consumed — status is '{$fresh->status}'."
            );
        }

        $fresh->update([
            'status'                      => 'consumed',
            'used_in_cash_transaction_id' => $cashTransactionId,
        ]);
    }
}
