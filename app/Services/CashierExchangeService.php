<?php

namespace App\Services;

use App\Models\CashierShift;
use App\Models\CashTransaction;
use Illuminate\Support\Facades\DB;

class CashierExchangeService
{
    /**
     * Record a currency exchange against an open shift.
     *
     * Creates two CashTransaction records (in + out) atomically inside
     * DB::transaction with lockForUpdate + revalidation.
     *
     * @param  int    $shiftId       Shift to record exchange against
     * @param  array  $exchangeData  Keys: in_amount, in_currency, out_amount, out_currency
     * @param  int    $createdBy     User ID of the cashier
     * @param  string $callbackId    Telegram callback ID for idempotency (empty = skip)
     * @return string                Exchange reference (e.g. "EX-20260317143022")
     *
     * @throws \RuntimeException If shift is closed or not found under lock
     * @throws \Exception        On any DB failure (transaction auto-rolls back)
     */
    public function recordExchange(int $shiftId, array $exchangeData, int $createdBy, string $callbackId = ''): string
    {
        $ref = 'EX-' . now()->format('YmdHis');

        DB::transaction(function () use ($shiftId, $exchangeData, $createdBy, $callbackId, $ref) {
            // Lock shift row and revalidate — another request may have closed it
            $lockedShift = CashierShift::where('id', $shiftId)->lockForUpdate()->first();
            if (!$lockedShift || !$lockedShift->isOpen()) {
                throw new \RuntimeException('Shift closed during confirmation');
            }

            // Cash IN (receiving money) — must succeed together with cash OUT
            CashTransaction::create([
                'cashier_shift_id' => $lockedShift->id,
                'type'             => 'in',
                'amount'           => $exchangeData['in_amount'],
                'currency'         => $exchangeData['in_currency'],
                'related_currency' => $exchangeData['out_currency'],
                'related_amount'   => $exchangeData['out_amount'],
                'category'         => 'exchange',
                'reference'        => $ref,
                'notes'            => "Обмен: " . number_format($exchangeData['in_amount'], 0) . " {$exchangeData['in_currency']} ← " . number_format($exchangeData['out_amount'], 0) . " {$exchangeData['out_currency']}",
                'created_by'       => $createdBy,
                'occurred_at'      => now(),
            ]);

            // Cash OUT (giving money) — atomic with cash IN
            CashTransaction::create([
                'cashier_shift_id' => $lockedShift->id,
                'type'             => 'out',
                'amount'           => $exchangeData['out_amount'],
                'currency'         => $exchangeData['out_currency'],
                'related_currency' => $exchangeData['in_currency'],
                'related_amount'   => $exchangeData['in_amount'],
                'category'         => 'exchange',
                'reference'        => $ref,
                'notes'            => "Обмен: " . number_format($exchangeData['out_amount'], 0) . " {$exchangeData['out_currency']} → " . number_format($exchangeData['in_amount'], 0) . " {$exchangeData['in_currency']}",
                'created_by'       => $createdBy,
                'occurred_at'      => now(),
            ]);

            if ($callbackId) {
                $this->succeedCallback($callbackId);
            }
        });

        return $ref;
    }

    private function succeedCallback(string $callbackId): void
    {
        DB::table('telegram_processed_callbacks')
            ->where('callback_query_id', $callbackId)
            ->where('status', 'processing')
            ->update(['status' => 'succeeded', 'completed_at' => now()]);
    }
}
