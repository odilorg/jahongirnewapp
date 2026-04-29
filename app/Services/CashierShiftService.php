<?php

namespace App\Services;

use App\Enums\Currency;
use App\Enums\OverrideTier;
use App\Models\CashierShift;
use App\Models\EndSaldo;
use App\Models\ShiftHandover;
use Illuminate\Support\Facades\DB;

class CashierShiftService
{
    /**
     * Close a shift: create handover + EndSaldo records, mark shift closed.
     *
     * Runs inside DB::transaction with lockForUpdate + revalidation.
     * Marks the callback as succeeded inside the transaction.
     *
     * Accepts shifts in `open` OR `under_review` status — the latter is the
     * pending-approval state introduced by C1.2 (RequestShiftCloseApproval →
     * ApproveShiftCloseAction calls back into closeShift to commit).
     *
     * @param  int               $shiftId    Shift to close
     * @param  array             $countData  Keys: counted_uzs, counted_usd, counted_eur, expected (array), photo_id
     * @param  string            $callbackId Telegram callback ID for idempotency (empty = skip)
     * @param  OverrideTier      $tier       Discrepancy classification (None when no escalation needed)
     * @param  string|null       $reason     Cashier-supplied reason; overrides default 'Via Telegram bot' on EndSaldo rows
     * @return ShiftHandover                 The created handover record
     *
     * @throws \RuntimeException If shift is already closed or not found under lock
     * @throws \Exception        On any DB failure (transaction auto-rolls back)
     */
    public function closeShift(
        int $shiftId,
        array $countData,
        string $callbackId = '',
        OverrideTier $tier = OverrideTier::None,
        ?string $reason = null,
    ): ShiftHandover {
        $handover = null;

        DB::transaction(function () use ($shiftId, $countData, $callbackId, $tier, $reason, &$handover) {
            // Lock shift to prevent concurrent close
            $lockedShift = CashierShift::where('id', $shiftId)->lockForUpdate()->first();
            // Null check FIRST — accessing ->status on null crashes without this guard
            if (! $lockedShift) {
                throw new \RuntimeException('Shift not found');
            }
            // status is a ShiftStatus enum — compare via ->value
            $statusValue = $lockedShift->status instanceof \App\Enums\ShiftStatus
                ? $lockedShift->status->value
                : (string) $lockedShift->status;
            if (! in_array($statusValue, ['open', 'under_review'], true)) {
                throw new \RuntimeException('Shift already closed');
            }

            $expected = $countData['expected'] ?? [];

            $handover = ShiftHandover::create([
                'outgoing_shift_id' => $lockedShift->id,
                'counted_uzs'       => $countData['counted_uzs'] ?? 0,
                'counted_usd'       => $countData['counted_usd'] ?? 0,
                'counted_eur'       => $countData['counted_eur'] ?? 0,
                'expected_uzs'      => $expected['UZS'] ?? 0,
                'expected_usd'      => $expected['USD'] ?? 0,
                'expected_eur'      => $expected['EUR'] ?? 0,
                'cash_photo_path'   => $countData['photo_id'] ?? null,
            ]);

            // Write status + classification atomically. forceFill avoids any
            // future $fillable drift (per feedback_no_mass_assign_for_system_state).
            $shiftUpdates = [
                'status'    => 'closed',
                'closed_at' => now(),
            ];
            if ($tier !== OverrideTier::None) {
                $shiftUpdates['discrepancy_tier']         = $tier;
                $shiftUpdates['discrepancy_severity_uzs'] = (float) ($countData['severity_uzs'] ?? 0);
            }
            $lockedShift->forceFill($shiftUpdates)->save();

            // EndSaldo records — atomic with shift close
            $reasonText = $reason !== null && trim($reason) !== '' ? $reason : 'Via Telegram bot';
            foreach (['UZS', 'USD', 'EUR'] as $cur) {
                $exp = $expected[$cur] ?? 0;
                $cnt = $countData['counted_' . strtolower($cur)] ?? 0;
                if ($exp > 0 || $cnt > 0) {
                    EndSaldo::updateOrCreate(
                        ['cashier_shift_id' => $lockedShift->id, 'currency' => Currency::from($cur)],
                        [
                            'expected_end_saldo'  => $exp,
                            'counted_end_saldo'   => $cnt,
                            'discrepancy'         => round($cnt - $exp, 2),
                            'discrepancy_reason'  => abs($cnt - $exp) > 0.01 ? $reasonText : null,
                        ]
                    );
                }
            }

            if ($callbackId) {
                $this->succeedCallback($callbackId);
            }
        });

        return $handover;
    }

    /**
     * Mark callback as succeeded (inside transaction boundary).
     */
    private function succeedCallback(string $callbackId): void
    {
        DB::table('telegram_processed_callbacks')
            ->where('callback_query_id', $callbackId)
            ->where('status', 'processing')
            ->update(['status' => 'succeeded', 'completed_at' => now()]);
    }
}
