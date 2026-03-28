<?php

namespace App\Services;

use App\Models\Beds24Booking;
use App\Models\DailyExchangeRate;

/**
 * Calculates the UZS / EUR / RUB payment options for a Beds24 booking
 * based on a given DailyExchangeRate snapshot.
 *
 * All amounts are derived from a single UZS pivot so the three figures
 * are internally consistent:
 *
 *   uzs_exact = booking_usd × usd_uzs_rate
 *   eur_final = ceil(uzs_exact / eur_effective_rate, eur_rounding_increment)
 *   rub_final = ceil(uzs_exact / rub_effective_rate, rub_rounding_increment)
 *   uzs_final = ceil(uzs_exact, uzs_rounding_increment)
 *
 * Result array is intentionally verbose so callers can log, display, or
 * write to Beds24 infoItems without re-running the math.
 */
class BookingPaymentOptionsService
{
    /**
     * Calculate payment options for a booking.
     *
     * @param  float            $usdAmount   Booking invoice amount in USD
     * @param  DailyExchangeRate $rate        Today's rate row
     * @return array{
     *   usd_amount: float,
     *   uzs_exact: float,
     *   uzs_final: int,
     *   uzs_rate: float,
     *   eur_exact: float,
     *   eur_final: int,
     *   eur_rate_cbu: float,
     *   eur_margin: int,
     *   eur_effective_rate: float,
     *   rub_exact: float,
     *   rub_final: int,
     *   rub_rate_cbu: float,
     *   rub_margin: int,
     *   rub_effective_rate: float,
     * }
     */
    public function calculate(float $usdAmount, DailyExchangeRate $rate): array
    {
        $uzsExact = $usdAmount * (float) $rate->usd_uzs_rate;

        $eurExact = $uzsExact / (float) $rate->eur_effective_rate;
        $rubExact = $uzsExact / (float) $rate->rub_effective_rate;

        return [
            'usd_amount' => round($usdAmount, 2),

            'uzs_exact' => round($uzsExact, 2),
            'uzs_final' => $this->ceilToIncrement($uzsExact, $rate->uzs_rounding_increment),
            'uzs_rate'  => (float) $rate->usd_uzs_rate,

            'eur_exact'          => round($eurExact, 4),
            'eur_final'          => $this->ceilToIncrement($eurExact, $rate->eur_rounding_increment),
            'eur_rate_cbu'       => (float) $rate->eur_uzs_cbu_rate,
            'eur_margin'         => (int) $rate->eur_margin,
            'eur_effective_rate' => (float) $rate->eur_effective_rate,

            'rub_exact'          => round($rubExact, 4),
            'rub_final'          => $this->ceilToIncrement($rubExact, $rate->rub_rounding_increment),
            'rub_rate_cbu'       => (float) $rate->rub_uzs_cbu_rate,
            'rub_margin'         => (int) $rate->rub_margin,
            'rub_effective_rate' => (float) $rate->rub_effective_rate,
        ];
    }

    /**
     * Format options as Beds24 infoItem values.
     *
     * Returns an associative array of code → display string ready to be
     * passed to Beds24BookingService::writePaymentOptionsToInfoItems().
     *
     * Example output:
     *   UZS_AMOUNT → "490 000"
     *   EUR_AMOUNT → "37"
     *   RUB_AMOUNT → "3 800"
     *   UZS_RATE   → "12 100"
     *   EUR_RATE   → "13 400 (CBU 13 600 - 200)"
     *   RUB_RATE   → "130 (CBU 150 - 20)"
     *   FX_DATE    → "28.03.2026"
     */
    public function formatForBeds24(array $options, string $rateDate): array
    {
        return [
            'UZS_AMOUNT'   => $this->fmtInt($options['uzs_final']),
            'EUR_AMOUNT'   => $this->fmtInt($options['eur_final']),
            'RUB_AMOUNT'   => $this->fmtInt($options['rub_final']),
            // Clean rates — just the effective number, for display on the printed form
            'UZS_RATE'     => $this->fmtInt((int) round($options['uzs_rate'])),
            'EUR_EFF_RATE' => $this->fmtInt((int) round($options['eur_effective_rate'])),
            'RUB_EFF_RATE' => $this->fmtInt((int) round($options['rub_effective_rate'])),
            // Full rates — include CBU + margin breakdown, for internal audit
            'EUR_RATE'     => $this->fmtEffectiveRate(
                $options['eur_effective_rate'],
                $options['eur_rate_cbu'],
                $options['eur_margin']
            ),
            'RUB_RATE'     => $this->fmtEffectiveRate(
                $options['rub_effective_rate'],
                $options['rub_rate_cbu'],
                $options['rub_margin']
            ),
            'FX_DATE'      => \Carbon\Carbon::parse($rateDate)->format('d.m.Y'),
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Ceil a value to the nearest multiple of $increment.
     * Used for all three currencies with different increments.
     *
     * Examples:
     *   ceilToIncrement(1_898_732, 10_000) = 1_900_000
     *   ceilToIncrement(36.11, 1)          = 37
     *   ceilToIncrement(3_723.07, 100)     = 3_800
     */
    public function ceilToIncrement(float $value, int $increment): int
    {
        if ($increment <= 0) {
            return (int) ceil($value);
        }
        return (int) (ceil($value / $increment) * $increment);
    }

    /** Format an integer with thin-space thousand separators (matches user's style). */
    public function formatCurrencyAmount(string $currency, float $amount): string
    {
        return match (strtoupper($currency)) {
            'UZS' => $this->fmtInt((int) $amount) . ' UZS',
            'EUR' => '€ ' . $this->fmtInt((int) $amount),
            'RUB' => $this->fmtInt((int) $amount) . ' ₽',
            'USD' => '$ ' . number_format($amount, 2),
            default => (string) $amount . ' ' . strtoupper($currency),
        };
    }

    /** Space-separated thousands: 490000 → "490 000" */
    private function fmtInt(int $n): string
    {
        return number_format($n, 0, '.', ' ');
    }

    /** "13 400 (CBU 13 600 - 200)" */
    private function fmtEffectiveRate(float $effective, float $cbu, int $margin): string
    {
        return $this->fmtInt((int) round($effective))
            . ' (CBU ' . $this->fmtInt((int) round($cbu)) . ' - ' . $margin . ')';
    }
}
