<?php

namespace App\Services;

use App\Models\CashierShift;
use App\Models\CashTransaction;
use Illuminate\Support\Facades\DB;

class CashierPaymentService
{
    /**
     * Record a guest payment against an open shift.
     *
     * Always records in the payment currency (UZS for cash). The UZS amount
     * is the authoritative figure for the physical vault balance.
     *
     * When the Beds24 booking is in a different currency (e.g. USD) and the
     * guest pays in UZS, the FX columns are populated so we can compute:
     *
     *   collection_variance = amount_uzs - (booking_usd × applied_rate)   [≈ 0]
     *   fx_variance         = amount_uzs - (booking_usd × reference_rate)  [management KPI]
     *
     * @param  int    $shiftId      Shift to record against
     * @param  array  $paymentData  Keys:
     *                                amount            – UZS received (float)
     *                                currency          – payment currency ('UZS')
     *                                method            – cash | card | transfer
     *                                guest_name        – string
     *                                room              – room number string
     *                                booking_id        – Beds24 booking id (nullable)
     *                                booking_currency  – Beds24 invoice currency, e.g. 'USD' (nullable)
     *                                booking_amount    – Beds24 invoice amount (nullable float)
     *                                applied_rate      – rate cashier used (nullable float)
     *                                reference_rate    – auto-fetched CBU/fallback rate (nullable float)
     *                                reference_source  – 'cbu' | 'er_api' | 'floatrates' | 'manual_only'
     *                                reference_date    – effective date string 'Y-m-d' (nullable)
     * @param  int    $createdBy    User ID of the cashier
     * @param  string $callbackId   Telegram callback ID for idempotency (empty = skip)
     * @return CashTransaction      The created sale transaction
     *
     * @throws \RuntimeException If shift is closed or not found under lock
     * @throws \Exception        On any DB failure (transaction auto-rolls back)
     */
    public function recordPayment(int $shiftId, array $paymentData, int $createdBy, string $callbackId = ''): CashTransaction
    {
        $transaction = null;

        DB::transaction(function () use ($shiftId, $paymentData, $createdBy, $callbackId, &$transaction) {
            // Lock shift row and revalidate — another request may have closed it
            $lockedShift = CashierShift::where('id', $shiftId)->lockForUpdate()->first();
            if (!$lockedShift || !$lockedShift->isOpen()) {
                throw new \RuntimeException('Shift closed during confirmation');
            }

            $isCrossCurrency = !empty($paymentData['booking_currency'])
                && $paymentData['booking_currency'] !== $paymentData['currency']
                && !empty($paymentData['booking_amount'])
                && !empty($paymentData['applied_rate']);

            // Build notes — include FX line when cross-currency
            $notes = "Оплата: {$paymentData['guest_name']}";
            if ($isCrossCurrency) {
                $notes .= "\nКурс: " . number_format((float)$paymentData['applied_rate'], 2)
                    . " UZS/{$paymentData['booking_currency']}"
                    . " (брон.: {$paymentData['booking_amount']} {$paymentData['booking_currency']})";
            }

            $transaction = CashTransaction::create([
                'cashier_shift_id'        => $lockedShift->id,
                'type'                    => 'in',
                'amount'                  => $paymentData['amount'],
                'currency'                => $paymentData['currency'],
                'category'                => 'sale',
                'beds24_booking_id'       => $paymentData['booking_id'] ?? null,
                'payment_method'          => $paymentData['method'],
                'guest_name'              => $paymentData['guest_name'],
                'room_number'             => $paymentData['room'],
                'reference'               => !empty($paymentData['booking_id'])
                    ? "Beds24 #{$paymentData['booking_id']}"
                    : "Комната {$paymentData['room']}",
                'notes'                   => $notes,
                'created_by'              => $createdBy,
                'occurred_at'             => now(),
                // FX tracking (null for same-currency payments)
                'booking_currency'        => $isCrossCurrency ? $paymentData['booking_currency'] : null,
                'booking_amount'          => $isCrossCurrency ? $paymentData['booking_amount']   : null,
                'applied_exchange_rate'   => $isCrossCurrency ? $paymentData['applied_rate']     : null,
                'reference_exchange_rate' => $isCrossCurrency ? ($paymentData['reference_rate']   ?? null) : null,
                'reference_rate_source'   => $isCrossCurrency ? ($paymentData['reference_source'] ?? null) : null,
                'reference_rate_date'     => $isCrossCurrency ? ($paymentData['reference_date']   ?? null) : null,
            ]);

            // Inside transaction: if this rolls back, callback stays 'processing' (retryable)
            if ($callbackId) {
                $this->succeedCallback($callbackId);
            }
        });

        return $transaction;
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
