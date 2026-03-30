<?php

namespace App\Services\Fx;

use App\DTOs\Fx\RemainingBalance;
use App\Enums\CashTransactionSource;
use App\Models\CashTransaction;

/**
 * Calculates how much of a booking's USD amount has already been settled
 * by drawer-truth transactions, and how much remains.
 */
class SettlementCalculator
{
    /**
     * @param  string  $beds24BookingId
     * @param  float   $totalUsd  Full booking amount from Beds24
     */
    public function remaining(string $beds24BookingId, float $totalUsd): RemainingBalance
    {
        $paidUsd = CashTransaction::query()
            ->drawerTruth()                              // cashier_bot + manual_admin only
            ->where('beds24_booking_id', $beds24BookingId)
            ->whereNotNull('usd_equivalent_paid')
            ->sum('usd_equivalent_paid');

        $paidUsd     = (float) $paidUsd;
        $remainingUsd = max(0.0, $totalUsd - $paidUsd);

        return new RemainingBalance(
            beds24BookingId: $beds24BookingId,
            totalUsd:        $totalUsd,
            paidUsd:         $paidUsd,
            remainingUsd:    $remainingUsd,
        );
    }
}
