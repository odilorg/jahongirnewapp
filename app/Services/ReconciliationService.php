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
     * Compares Beds24 expected payments vs actual CashTransaction records.
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
        // (arrived before end, departs after start)
        $bookings = Beds24Booking::where('booking_status', '!=', 'cancelled')
            ->where(function ($q) use ($from, $to) {
                // Bookings overlapping with period
                $q->where('arrival_date', '<=', $to->toDateString())
                  ->where('departure_date', '>=', $from->toDateString());
            })
            ->orWhere(function ($q) use ($from, $to) {
                // Or bookings created in the period (new bookings)
                $q->whereBetween('created_at', [$from, $to->copy()->endOfDay()]);
            })
            ->where('booking_status', '!=', 'cancelled')
            ->get();

        foreach ($bookings as $booking) {
            $results['total']++;

            // Expected: total amount from Beds24 (what guest should pay)
            $expectedAmount = (float) $booking->total_amount;
            $currency = $booking->currency ?? 'USD';

            // Reported: sum of all CashTransactions linked to this booking
            $reportedAmount = (float) CashTransaction::where('beds24_booking_id', $booking->beds24_booking_id)
                ->where('type', TransactionType::IN)
                ->sum('amount');

            // Calculate discrepancy
            $discrepancy = round($expectedAmount - $reportedAmount, 2);

            // Determine status
            $status = $this->determineStatus($expectedAmount, $reportedAmount, $discrepancy);

            // Create or update reconciliation record
            $recon = BookingPaymentReconciliation::updateOrCreate(
                ['beds24_booking_id' => $booking->beds24_booking_id],
                [
                    'property_id'       => $booking->property_id,
                    'expected_amount'   => $expectedAmount,
                    'reported_amount'   => $reportedAmount,
                    'discrepancy_amount'=> $discrepancy,
                    'currency'          => $currency,
                    'status'            => $status,
                    'flagged_at'        => in_array($status, ['underpaid', 'overpaid', 'no_payment'])
                        ? ($recon->flagged_at ?? now()) : null,
                ]
            );

            $results[$status]++;

            // Track flagged items for alerting
            if (in_array($status, ['underpaid', 'no_payment'])) {
                $results['flagged'][] = [
                    'booking_id'   => $booking->beds24_booking_id,
                    'guest'        => $booking->guest_name ?: 'Не указан',
                    'property'     => $booking->getPropertyName(),
                    'room'         => $booking->room_name ?: '—',
                    'expected'     => $expectedAmount,
                    'reported'     => $reportedAmount,
                    'discrepancy'  => $discrepancy,
                    'currency'     => $currency,
                    'status'       => $status,
                    'dates'        => $booking->arrival_date?->format('d.m') . '–' . $booking->departure_date?->format('d.m'),
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

            $reportedAmount = (float) CashTransaction::where('beds24_booking_id', $booking->beds24_booking_id)
                ->where('type', TransactionType::IN)
                ->sum('amount');

            $discrepancy = round($expectedAmount - $reportedAmount, 2);
            $status = $this->determineStatus($expectedAmount, $reportedAmount, $discrepancy);

            BookingPaymentReconciliation::updateOrCreate(
                ['beds24_booking_id' => $booking->beds24_booking_id],
                [
                    'property_id'       => $booking->property_id,
                    'expected_amount'   => $expectedAmount,
                    'reported_amount'   => $reportedAmount,
                    'discrepancy_amount'=> $discrepancy,
                    'currency'          => $currency,
                    'status'            => $status,
                    'flagged_at'        => in_array($status, ['underpaid', 'overpaid', 'no_payment'])
                        ? now() : null,
                ]
            );

            $results[$status]++;

            if (in_array($status, ['underpaid', 'no_payment'])) {
                $results['flagged'][] = [
                    'booking_id'   => $booking->beds24_booking_id,
                    'guest'        => $booking->guest_name ?: 'Не указан',
                    'property'     => $booking->getPropertyName(),
                    'room'         => $booking->room_name ?: '—',
                    'expected'     => $expectedAmount,
                    'reported'     => $reportedAmount,
                    'discrepancy'  => $discrepancy,
                    'currency'     => $currency,
                    'status'       => $status,
                ];
            }
        }

        return $results;
    }

    /**
     * Cross-check with Beds24 API: fetch fresh invoice data
     * and compare with our local records
     */
    public function crossCheckWithApi(string $bookingId, Beds24BookingService $apiService): ?array
    {
        try {
            $apiData = $apiService->getBooking($bookingId);

            if (empty($apiData)) {
                return null;
            }

            // Extract from first booking in response
            $bookingData = is_array($apiData) && isset($apiData[0]) ? $apiData[0] : $apiData;
            $invoiceItems = $bookingData['invoiceItems'] ?? [];

            // Sum API payments
            $apiPayments = array_filter($invoiceItems, fn($i) => ($i['type'] ?? '') === 'payment');
            $apiTotal = array_sum(array_column($apiPayments, 'amount'));

            // Sum local CashTransactions
            $localTotal = (float) CashTransaction::where('beds24_booking_id', $bookingId)
                ->where('type', TransactionType::IN)
                ->sum('amount');

            // Check for missing payment lines
            $localRefs = CashTransaction::where('beds24_booking_id', $bookingId)
                ->pluck('reference')
                ->toArray();

            $missingLines = [];
            foreach ($apiPayments as $payment) {
                $ref = "Beds24 #{$bookingId} item#{$payment['id']}";
                if (!in_array($ref, $localRefs)) {
                    $missingLines[] = $payment;
                }
            }

            return [
                'booking_id'    => $bookingId,
                'api_total'     => $apiTotal,
                'local_total'   => $localTotal,
                'discrepancy'   => round($apiTotal - $localTotal, 2),
                'api_lines'     => count($apiPayments),
                'local_lines'   => count($localRefs),
                'missing_lines' => $missingLines,
                'match'         => abs($apiTotal - $localTotal) < 0.01,
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
        // Tolerance: $0.50 or 500 UZS
        $tolerance = 0.50;

        if ($expected == 0 && $reported == 0) {
            return 'matched'; // Nothing expected, nothing received
        }

        if ($expected > 0 && $reported == 0) {
            return 'no_payment';
        }

        if (abs($discrepancy) <= $tolerance) {
            return 'matched';
        }

        if ($discrepancy > $tolerance) {
            return 'underpaid'; // Expected more than reported
        }

        return 'overpaid'; // Reported more than expected
    }
}
