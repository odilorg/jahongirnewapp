<?php

declare(strict_types=1);

namespace App\Services\Calendar\Support;

use App\Models\BookingInquiry;
use App\Models\SupplierPayment;

/**
 * Computes supplier-payment + financial summaries for the calendar
 * slide-over.
 *
 * Scope (kept narrow on purpose — see docs/architecture/PRINCIPLES.md):
 *   - aggregates paid / remaining per role (driver, guide, per-stay)
 *   - totals the inquiry-level financial panel (cost/margin)
 *   - prepares recent guest-payment stats
 *
 * NOT the place for:
 *   - writes (those belong in Actions)
 *   - Telegram / notifications (those go through services)
 *   - Blade rendering decisions beyond "color = red|green based on > 0"
 */
final class PaymentSummaryBuilder
{
    private const RED   = '#dc2626';
    private const GREEN = '#16a34a';

    /**
     * @return array{
     *   driver: array{cost: float, paid: float, remaining: float, color: string},
     *   guide: array{cost: float, paid: float, remaining: float, color: string},
     *   stays: array<int, array{cost: float, paid: float, remaining: float, color: string}>,
     *   totals: array<string, float|int|bool>,
     *   guest: array<string, mixed>
     * }
     */
    public function buildForInquiry(BookingInquiry $inquiry): array
    {
        $driver = $this->supplierSummary('driver', $inquiry->driver_id, (float) ($inquiry->driver_cost ?? 0), $inquiry);
        $guide  = $this->supplierSummary('guide',  $inquiry->guide_id,  (float) ($inquiry->guide_cost  ?? 0), $inquiry);

        $stays = [];
        $accCost = 0.0;
        foreach ($inquiry->stays as $stay) {
            $paid = $stay->accommodation_id
                ? (float) SupplierPayment::forSupplier('accommodation', $stay->accommodation_id)
                    ->where('booking_inquiry_id', $inquiry->id)
                    ->sum('amount')
                : 0.0;
            $cost = (float) ($stay->total_accommodation_cost ?? 0);
            $stays[$stay->id] = [
                'cost'      => $cost,
                'paid'      => $paid,
                'remaining' => $cost - $paid,
                'color'     => ($cost - $paid) > 0 ? self::RED : self::GREEN,
            ];
            $accCost += $cost;
        }

        // Inquiry-level financial panel
        $driverCost    = $inquiry->driver_id ? (float) ($inquiry->driver_cost ?? 0) : 0.0;
        $guideCost     = $inquiry->guide_id  ? (float) ($inquiry->guide_cost  ?? 0) : 0.0;
        $otherCosts    = (float) ($inquiry->other_costs ?? 0);
        $totalCost     = $accCost + $driverCost + $guideCost + $otherCosts;
        $gross         = (float) ($inquiry->price_quoted ?? 0);
        $commission    = (float) ($inquiry->commission_amount ?? 0);
        $netRevenue    = (float) $inquiry->effectiveRevenue();
        $margin        = $netRevenue - $totalCost;
        $marginPct     = $netRevenue > 0 ? (int) round($margin / $netRevenue * 100) : 0;

        // Guest payments (top 5 recent + outstanding)
        $guestPayments = $inquiry->guestPayments()
            ->orderBy('payment_date', 'desc')
            ->limit(5)
            ->get();
        $totalReceived = (float) $guestPayments->sum('amount');
        $outstanding   = $gross - $totalReceived;

        return [
            'driver' => $driver,
            'guide'  => $guide,
            'stays'  => $stays,
            'totals' => [
                'acc_cost'       => $accCost,
                'driver_cost'    => $driverCost,
                'guide_cost'     => $guideCost,
                'other_costs'    => $otherCosts,
                'total_cost'     => $totalCost,
                'gross'          => $gross,
                'commission'     => $commission,
                'net_revenue'    => $netRevenue,
                'margin'         => $margin,
                'margin_pct'     => $marginPct,
                'has_commission' => $commission > 0,
                'acc_cost_multi' => $accCost > 0 && $inquiry->stays->count() > 1,
            ],
            'guest' => [
                'quoted'          => $gross,
                'received'        => $totalReceived,
                'outstanding'     => $outstanding,
                'recent_payments' => $guestPayments,
            ],
        ];
    }

    /**
     * @return array{cost: float, paid: float, remaining: float, color: string}
     */
    private function supplierSummary(string $role, ?int $supplierId, float $cost, BookingInquiry $inquiry): array
    {
        $paid = ($supplierId && $cost > 0)
            ? (float) SupplierPayment::forSupplier($role, $supplierId)
                ->where('booking_inquiry_id', $inquiry->id)
                ->sum('amount')
            : 0.0;
        $remaining = $cost - $paid;
        return [
            'cost'      => $cost,
            'paid'      => $paid,
            'remaining' => $remaining,
            'color'     => $remaining > 0 ? self::RED : self::GREEN,
        ];
    }
}
