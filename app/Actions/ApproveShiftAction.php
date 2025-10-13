<?php

namespace App\Actions;

use App\Enums\ShiftStatus;
use App\Models\CashierShift;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApproveShiftAction
{
    /**
     * Approve a shift under review
     * Manager reviews and approves the shift, changing status to CLOSED
     */
    public function approve(CashierShift $shift, User $manager, ?string $notes = null): CashierShift
    {
        return DB::transaction(function () use ($shift, $manager, $notes) {
            // Check if shift is under review
            if ($shift->status !== ShiftStatus::UNDER_REVIEW) {
                throw ValidationException::withMessages([
                    'shift' => 'Only shifts under review can be approved.'
                ]);
            }

            // Update shift status to closed
            $shift->update([
                'status' => ShiftStatus::CLOSED,
                'approved_by' => $manager->id,
                'approved_at' => now(),
                'approval_notes' => $notes,
            ]);

            // TODO: Send notification to cashier about approval
            // Notification::send($shift->user, new ShiftApprovedNotification($shift));

            return $shift->fresh();
        });
    }

    /**
     * Reject a shift under review
     * Manager rejects the shift, keeping it in UNDER_REVIEW status
     * Cashier must recount and resubmit
     */
    public function reject(CashierShift $shift, User $manager, string $reason): CashierShift
    {
        return DB::transaction(function () use ($shift, $manager, $reason) {
            // Check if shift is under review
            if ($shift->status !== ShiftStatus::UNDER_REVIEW) {
                throw ValidationException::withMessages([
                    'shift' => 'Only shifts under review can be rejected.'
                ]);
            }

            // Validate reason is provided
            if (empty($reason)) {
                throw ValidationException::withMessages([
                    'reason' => 'Rejection reason is required.'
                ]);
            }

            // Update shift with rejection info
            $shift->update([
                'status' => ShiftStatus::OPEN, // Reopen shift for recount
                'rejection_reason' => $reason,
                'rejected_by' => $manager->id,
                'rejected_at' => now(),
            ]);

            // TODO: Send notification to cashier about rejection
            // Notification::send($shift->user, new ShiftRejectedNotification($shift, $reason));

            return $shift->fresh();
        });
    }

    /**
     * Approve with adjustment
     * Manager approves but makes corrections to the counted amounts
     */
    public function approveWithAdjustment(
        CashierShift $shift,
        User $manager,
        array $adjustedAmounts,
        string $adjustmentReason
    ): CashierShift {
        return DB::transaction(function () use ($shift, $manager, $adjustedAmounts, $adjustmentReason) {
            // Check if shift is under review
            if ($shift->status !== ShiftStatus::UNDER_REVIEW) {
                throw ValidationException::withMessages([
                    'shift' => 'Only shifts under review can be adjusted.'
                ]);
            }

            // Validate adjustment reason is provided
            if (empty($adjustmentReason)) {
                throw ValidationException::withMessages([
                    'reason' => 'Adjustment reason is required.'
                ]);
            }

            // Update end saldos with adjusted amounts
            foreach ($adjustedAmounts as $currencyCode => $adjustedAmount) {
                $endSaldo = $shift->endSaldos()->where('currency', $currencyCode)->first();

                if ($endSaldo) {
                    $endSaldo->update([
                        'counted_end_saldo' => $adjustedAmount,
                        'discrepancy' => $adjustedAmount - $endSaldo->expected_end_saldo,
                        'adjusted_by' => $manager->id,
                        'adjustment_reason' => $adjustmentReason,
                    ]);
                }
            }

            // Update drawer balances with adjusted amounts
            $drawer = $shift->cashDrawer;
            $balances = [];
            foreach ($shift->endSaldos as $endSaldo) {
                $balances[$endSaldo->currency->value] = (float) $endSaldo->counted_end_saldo;
            }
            $drawer->balances = $balances;
            $drawer->save();

            // Update shift status to closed
            $shift->update([
                'status' => ShiftStatus::CLOSED,
                'approved_by' => $manager->id,
                'approved_at' => now(),
                'approval_notes' => "Approved with adjustments: {$adjustmentReason}",
            ]);

            // TODO: Send notification to cashier about approval with adjustment
            // Notification::send($shift->user, new ShiftApprovedWithAdjustmentNotification($shift));

            return $shift->fresh();
        });
    }
}
