<?php

namespace App\Services;

use App\Enums\Currency;
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
     * @param  int    $shiftId    Shift to close
     * @param  array  $countData  Keys: counted_uzs, counted_usd, counted_eur, expected (array), photo_id
     * @param  string $callbackId Telegram callback ID for idempotency (empty = skip)
     * @return ShiftHandover      The created handover record
     *
     * @throws \RuntimeException If shift is already closed or not found under lock
     * @throws \Exception        On any DB failure (transaction auto-rolls back)
     */
    public function closeShift(int $shiftId, array $countData, string $callbackId = ''): ShiftHandover
    {
        $handover = null;

        DB::transaction(function () use ($shiftId, $countData, $callbackId, &$handover) {
            // Lock shift to prevent concurrent close
            $lockedShift = CashierShift::where('id', $shiftId)->lockForUpdate()->first();
            // status is a ShiftStatus enum — compare via ->value
            $statusValue = $lockedShift->status instanceof \App\Enums\ShiftStatus
                ? $lockedShift->status->value
                : (string) $lockedShift->status;
            if (!$lockedShift || $statusValue !== 'open') {
                throw new \RuntimeException('Shift already closed or not found');
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

            $lockedShift->update(['status' => 'closed', 'closed_at' => now()]);

            // EndSaldo records — atomic with shift close
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
                            'discrepancy_reason'  => abs($cnt - $exp) > 0.01 ? 'Via Telegram bot' : null,
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
