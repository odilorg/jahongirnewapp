<?php

declare(strict_types=1);

namespace App\Actions\CashierBot\Handlers;

use App\DTOs\Cashier\ShiftCloseEvaluation;
use App\Enums\ShiftStatus;
use App\Models\CashierShift;
use App\Services\OwnerAlertService;
use Illuminate\Support\Facades\DB;

/**
 * C1.2 — transitions a shift to UNDER_REVIEW pending owner approval.
 *
 * Called when the discrepancy evaluator returns Manager tier. Persists the
 * tier + severity classification onto the shift, then dispatches an owner-
 * alert with approve/reject inline keyboard. Idempotent: if the shift is
 * already under review, returns it without re-sending the alert.
 *
 * The cashier session FSM is the caller's concern (will be C1.3) — this
 * action only owns the shift-state transition + alert dispatch.
 */
final class RequestShiftCloseApprovalAction
{
    public function __construct(
        private readonly OwnerAlertService $ownerAlert,
    ) {}

    public function execute(int $shiftId, ShiftCloseEvaluation $eval): CashierShift
    {
        $result = null;

        DB::transaction(function () use ($shiftId, $eval, &$result) {
            $shift = CashierShift::where('id', $shiftId)->lockForUpdate()->first();

            if (! $shift) {
                throw new \RuntimeException("Shift #{$shiftId} not found");
            }

            if ($shift->status === ShiftStatus::UNDER_REVIEW) {
                // Idempotent: already pending; return as-is, no second alert.
                $result = $shift;
                return;
            }

            if ($shift->status !== ShiftStatus::OPEN) {
                throw new \RuntimeException(
                    "Shift #{$shiftId} cannot enter under_review from status {$shift->status->value}"
                );
            }

            $shift->forceFill([
                'status'                   => ShiftStatus::UNDER_REVIEW,
                'discrepancy_tier'         => $eval->tier,
                'discrepancy_severity_uzs' => $eval->severityUzs,
            ])->save();

            $this->ownerAlert->requestShiftCloseApproval($shift->fresh(), $eval);
            $result = $shift->fresh();
        });

        return $result;
    }
}
