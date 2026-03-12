<?php

namespace App\Services;

use App\Models\Beds24Booking;
use App\Models\BookingPaymentReconciliation;
use App\Models\CashTransaction;
use App\Enums\TransactionType;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ReconciliationService
{
    /**
     * Reconcile bookings for a given date range.
     * Uses Beds24 invoice_balance as source of truth for payment status,
     * NOT CashTransaction records (which only track cash/naqd payments).
     *
     * @return array Summary of reconciliation results
     */
    public function reconcile(Carbon $from, Carbon $to): array
    {
        $results = [
            'matched'    => 0,
            'underpaid'  => 0,
            'overpaid'   => 0,
            'no_payment' => 0,
            'total'      => 0,
            'flagged'    => [],
        ];

        // Get all confirmed bookings that were active in this period
        $bookings = Beds24Booking::where('booking_status', '!=', 'cancelled')
            ->where(function ($q) use ($from, $to) {
                $q->where(function ($inner) use ($from, $to) {
                    // Bookings overlapping with period
                    $inner->where('arrival_date', '<=', $to->toDateString())
                          ->where('departure_date', '>=', $from->toDateString());
                })->orWhere(function ($inner) use ($from, $to) {
                    // Or bookings created in the period
                    $inner->whereBetween('created_at', [$from, $to->copy()->endOfDay()]);
                });
            })
            ->get();

        foreach ($bookings as $booking) {
            $results['total']++;

            $expectedAmount = (float) $booking->total_amount;
            $currency = $booking->currency ?? 'USD';

            // Use Beds24 invoice_balance as truth (not CashTransaction sum).
            // invoice_balance = 0 means fully paid, >0 means outstanding.
            $balance = (float) $booking->invoice_balance;
            $reportedAmount = round($expectedAmount - $balance, 2);

            $discrepancy = round($balance, 2);
            $status = $this->determineStatus($expectedAmount, $reportedAmount, $discrepancy);

            BookingPaymentReconciliation::updateOrCreate(
                ['beds24_booking_id' => $booking->beds24_booking_id],
                [
                    'property_id'        => $booking->property_id,
                    'expected_amount'    => $expectedAmount,
                    'reported_amount'    => $reportedAmount,
                    'discrepancy_amount' => $discrepancy,
                    'currency'           => $currency,
                    'status'             => $status,
                    'flagged_at'         => in_array($status, ['underpaid', 'overpaid', 'no_payment'])
                        ? now() : null,
                ]
            );

            $results[$status]++;

            if (in_array($status, ['underpaid', 'no_payment'])) {
                $results['flagged'][] = [
                    'booking_id'  => $booking->beds24_booking_id,
                    'guest'       => $booking->guest_name ?: 'Не указан',
                    'property'    => $booking->getPropertyName(),
                    'room'        => $booking->room_name ?: '—',
                    'expected'    => $expectedAmount,
                    'reported'    => $reportedAmount,
                    'discrepancy' => $discrepancy,
                    'currency'    => $currency,
                    'status'      => $status,
                    'dates'       => $booking->arrival_date?->format('d.m') . '–' . $booking->departure_date?->format('d.m'),
                ];
            }
        }

        return $results;
    }

    /**
     * Quick daily reconciliation: check today's departures
     * (guests who checked out = final payment should be complete)
     */
    public function reconcileDepartures(Carbon $date): array
    {
        $results = [
            'matched'    => 0,
            'underpaid'  => 0,
            'overpaid'   => 0,
            'no_payment' => 0,
            'total'      => 0,
            'flagged'    => [],
        ];

        $bookings = Beds24Booking::where('booking_status', '!=', 'cancelled')
            ->whereDate('departure_date', $date->toDateString())
            ->get();

        foreach ($bookings as $booking) {
            $results['total']++;

            $expectedAmount = (float) $booking->total_amount;
            $currency = $booking->currency ?? 'USD';

            // Use Beds24 invoice_balance as truth
            $balance = (float) $booking->invoice_balance;
            $reportedAmount = round($expectedAmount - $balance, 2);

            $discrepancy = round($balance, 2);
            $status = $this->determineStatus($expectedAmount, $reportedAmount, $discrepancy);

            BookingPaymentReconciliation::updateOrCreate(
                ['beds24_booking_id' => $booking->beds24_booking_id],
                [
                    'property_id'        => $booking->property_id,
                    'expected_amount'    => $expectedAmount,
                    'reported_amount'    => $reportedAmount,
                    'discrepancy_amount' => $discrepancy,
                    'currency'           => $currency,
                    'status'             => $status,
                    'flagged_at'         => in_array($status, ['underpaid', 'overpaid', 'no_payment'])
                        ? now() : null,
                ]
            );

            $results[$status]++;

            if (in_array($status, ['underpaid', 'no_payment'])) {
                $results['flagged'][] = [
                    'booking_id'  => $booking->beds24_booking_id,
                    'guest'       => $booking->guest_name ?: 'Не указан',
                    'property'    => $booking->getPropertyName(),
                    'room'        => $booking->room_name ?: '—',
                    'expected'    => $expectedAmount,
                    'reported'    => $reportedAmount,
                    'discrepancy' => $discrepancy,
                    'currency'    => $currency,
                    'status'      => $status,
                ];
            }
        }

        return $results;
    }

    /**
     * Cross-check with Beds24 API: fetch fresh invoice data
     * and compare with our local records
     */
    public function crossCheckWithApi(string $bookingId, $apiService): ?array
    {
        try {
            $apiData = $apiService->getBooking($bookingId);

            if (empty($apiData)) {
                return null;
            }

            $bookingData = is_array($apiData) && isset($apiData[0]) ? $apiData[0] : $apiData;
            $invoiceItems = $bookingData['invoiceItems'] ?? [];

            // Sum API payments
            $apiPayments = array_filter($invoiceItems, fn($i) => ($i['type'] ?? '') === 'payment');
            $apiTotal = array_sum(array_column($apiPayments, 'amount'));

            // Local booking record
            $booking = Beds24Booking::where('beds24_booking_id', $bookingId)->first();
            $localBalance = $booking ? (float) $booking->invoice_balance : null;

            return [
                'booking_id'    => $bookingId,
                'api_total'     => $apiTotal,
                'local_balance' => $localBalance,
                'api_lines'     => count($apiPayments),
                'match'         => $localBalance !== null && abs($localBalance) < 0.01,
            ];
        } catch (\Throwable $e) {
            Log::error('Reconciliation API cross-check failed', [
                'booking_id' => $bookingId,
                'error'      => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function determineStatus(float $expected, float $reported, float $discrepancy): string
    {
        $tolerance = 0.50;

        if ($expected == 0 && $reported == 0) {
            return 'matched';
        }

        if ($expected > 0 && $reported == 0) {
            return 'no_payment';
        }

        if (abs($discrepancy) <= $tolerance) {
            return 'matched';
        }

        if ($discrepancy > $tolerance) {
            return 'underpaid';
        }

        return 'overpaid';
    }
}
