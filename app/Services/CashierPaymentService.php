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
     * Runs inside DB::transaction with lockForUpdate + revalidation.
     * Marks the callback as succeeded inside the transaction so both
     * commit or roll back together.
     *
     * @param  int    $shiftId      Shift to record payment against
     * @param  array  $paymentData  Keys: amount, currency, method, guest_name, room, booking_id
     * @param  int    $createdBy    User ID of the cashier
     * @param  string $callbackId   Telegram callback ID for idempotency (empty = skip)
     * @return CashTransaction      The created transaction record
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

            $transaction = CashTransaction::create([
                'cashier_shift_id' => $lockedShift->id,
                'type'             => 'in',
                'amount'           => $paymentData['amount'],
                'currency'         => $paymentData['currency'],
                'category'         => 'sale',
                'beds24_booking_id' => $paymentData['booking_id'] ?? null,
                'payment_method'   => $paymentData['method'],
                'guest_name'       => $paymentData['guest_name'],
                'room_number'      => $paymentData['room'],
                'reference'        => !empty($paymentData['booking_id'])
                    ? "Beds24 #{$paymentData['booking_id']}"
                    : "Комната {$paymentData['room']}",
                'notes'            => "Оплата: {$paymentData['guest_name']}",
                'created_by'       => $createdBy,
                'occurred_at'      => now(),
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
