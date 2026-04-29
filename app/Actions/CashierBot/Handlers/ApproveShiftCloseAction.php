<?php

declare(strict_types=1);

namespace App\Actions\CashierBot\Handlers;

use App\Enums\OverrideTier;
use App\Enums\ShiftStatus;
use App\Models\CashierShift;
use App\Models\EndSaldo;
use App\Models\ShiftHandover;
use App\Models\User;
use App\Services\CashierShiftService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * C1.2 — owner-tier approval of a shift close that hit Manager tier.
 *
 * Role-gated to ['super_admin', 'admin']. Loads the existing tier + reason
 * from the shift and EndSaldo rows, then delegates to CashierShiftService
 * for the canonical close. After commit, stamps approver metadata.
 *
 * Idempotent on double-click: if the shift is already CLOSED with the same
 * approver, returns the existing handover without re-running.
 */
final class ApproveShiftCloseAction
{
    private const ALLOWED_ROLES = ['super_admin', 'admin'];

    public function __construct(
        private readonly CashierShiftService $shiftService,
    ) {}

    public function execute(
        int $shiftId,
        int $approverUserId,
        array $countData,
        ?string $approvalNotes = null,
    ): ShiftHandover {
        $approver = User::find($approverUserId);

        if (! $approver || ! $approver->hasAnyRole(self::ALLOWED_ROLES)) {
            throw new AuthorizationException(
                'Only super_admin or admin can approve shift close.'
            );
        }

        $shift = CashierShift::find($shiftId);
        if (! $shift) {
            throw new \RuntimeException("Shift #{$shiftId} not found");
        }

        // Idempotency on double-click: already approved by same user → return existing handover.
        if (
            $shift->status === ShiftStatus::CLOSED
            && (int) $shift->approved_by === $approverUserId
        ) {
            $handover = ShiftHandover::where('outgoing_shift_id', $shiftId)->first();
            if ($handover) {
                return $handover;
            }
        }

        if ($shift->status !== ShiftStatus::UNDER_REVIEW) {
            throw new \RuntimeException(
                "Shift #{$shiftId} is not under review (current: {$shift->status->value})"
            );
        }

        $tier   = $shift->discrepancy_tier ?? OverrideTier::None;
        $reason = $this->resolveReason($shift);

        $handover = $this->shiftService->closeShift(
            $shiftId,
            $countData,
            '',
            $tier,
            $reason,
        );

        // Stamp approver metadata — separate from closeShift so both writes
        // happen even when closeShift uses defaults for tier/reason.
        DB::transaction(function () use ($shiftId, $approverUserId, $approvalNotes) {
            $shift = CashierShift::where('id', $shiftId)->lockForUpdate()->first();
            $shift->forceFill([
                'approved_by'    => $approverUserId,
                'approved_at'    => now(),
                'approval_notes' => $approvalNotes,
            ])->save();
        });

        return $handover;
    }

    /**
     * Pull the cashier-supplied reason from any EndSaldo row that recorded it
     * during RequestShiftCloseApprovalAction's flow. Returns null if none.
     */
    private function resolveReason(CashierShift $shift): ?string
    {
        $row = EndSaldo::where('cashier_shift_id', $shift->id)
            ->whereNotNull('discrepancy_reason')
            ->where('discrepancy_reason', '!=', 'Via Telegram bot')
            ->first();

        return $row?->discrepancy_reason;
    }
}
