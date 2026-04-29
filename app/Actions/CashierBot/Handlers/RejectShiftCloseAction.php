<?php

declare(strict_types=1);

namespace App\Actions\CashierBot\Handlers;

use App\Enums\ShiftStatus;
use App\Models\CashierShift;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * C1.2 — owner-tier rejection of a shift close that hit Manager tier.
 *
 * Role-gated to ['super_admin', 'admin']. Returns shift to OPEN, stamps
 * rejecter metadata. Idempotent: re-call after rejection by same user
 * returns the (already-rejected) shift unchanged.
 *
 * Does NOT touch the cashier session FSM — that is C1.3's responsibility.
 * The bot will notice on next interaction (drawer-singleton holds; cashier
 * sees the shift is back in OPEN with rejection metadata stamped on it).
 */
final class RejectShiftCloseAction
{
    private const ALLOWED_ROLES = ['super_admin', 'admin'];

    public function execute(
        int $shiftId,
        int $rejecterUserId,
        string $rejectionReason,
    ): CashierShift {
        $rejecter = User::find($rejecterUserId);

        if (! $rejecter || ! $rejecter->hasAnyRole(self::ALLOWED_ROLES)) {
            throw new AuthorizationException(
                'Only super_admin or admin can reject shift close.'
            );
        }

        $result = null;

        DB::transaction(function () use ($shiftId, $rejecterUserId, $rejectionReason, &$result) {
            $shift = CashierShift::where('id', $shiftId)->lockForUpdate()->first();
            if (! $shift) {
                throw new \RuntimeException("Shift #{$shiftId} not found");
            }

            // Idempotent: already rejected by same user → no-op.
            if (
                $shift->status === ShiftStatus::OPEN
                && (int) $shift->rejected_by === $rejecterUserId
            ) {
                $result = $shift;
                return;
            }

            if ($shift->status !== ShiftStatus::UNDER_REVIEW) {
                throw new \RuntimeException(
                    "Shift #{$shiftId} is not under review (current: {$shift->status->value})"
                );
            }

            $shift->forceFill([
                'status'           => ShiftStatus::OPEN,
                'rejected_by'      => $rejecterUserId,
                'rejected_at'      => now(),
                'rejection_reason' => $rejectionReason,
            ])->save();

            $result = $shift->fresh();
        });

        return $result;
    }
}
