<?php

declare(strict_types=1);

namespace App\Services\Cashier;

use App\DTOs\Cashier\ShiftCloseEvaluation;
use App\Enums\OverrideTier;
use App\Models\DailyExchangeRate;
use Closure;

/**
 * Classifies a shift-close discrepancy into an OverrideTier.
 *
 * Pure logic: given expected vs counted balances per currency, returns the
 * tier the close should require (none / cashier reason / manager approval /
 * blocked). C1.1 — no DB writes, no logging, no events. The bot wiring in
 * C1.3 will read this DTO and route the FSM accordingly.
 *
 * Severity is sum of absolute UZS-equivalents across all currencies; this
 * intentionally does NOT cancel offsetting deltas (UZS +100k and USD −10
 * count as 100k + 127k = 227k severity, not 27k).
 *
 * If any currency's FX rate is older than `cashier.shift_close.fx_staleness_days`,
 * the tier is conservatively bumped to at least Manager so a real discrepancy
 * cannot slip through on outdated math.
 */
final class ShiftCloseDiscrepancyEvaluator
{
    /**
     * @param  Closure|null  $rateResolver  Test seam: callable(string $currency): array{rate: float, date: \Illuminate\Support\Carbon|null}.
     *                                       Defaults to `DailyExchangeRate::latest()`.
     */
    public function __construct(
        private readonly ?Closure $rateResolver = null,
    ) {}

    /**
     * @param  array<string, float|int|string>  $expected  e.g. ['UZS' => 1500000, 'USD' => 100, 'EUR' => 45]
     * @param  array<string, float|int|string>  $counted   same shape
     */
    public function evaluate(array $expected, array $counted): ShiftCloseEvaluation
    {
        $thresholds = config('cashier.shift_close');
        $reasonCap  = (float) $thresholds['reason_threshold_uzs'];
        $managerCap = (float) $thresholds['manager_threshold_uzs'];
        $stalenessDays = (int) $thresholds['fx_staleness_days'];

        $currencies = array_unique(array_merge(array_keys($expected), array_keys($counted)));

        $totalSeverity = 0.0;
        $fxStale = false;
        $breakdown = [];

        foreach ($currencies as $currency) {
            $exp = (float) ($expected[$currency] ?? 0);
            $cnt = (float) ($counted[$currency] ?? 0);
            $delta = $cnt - $exp;

            if ($delta == 0.0) {
                $breakdown[$currency] = ['delta' => 0.0, 'rate' => 1.0, 'uzs_equiv' => 0.0];
                continue;
            }

            [$rate, $stale] = $this->rateFor($currency, $stalenessDays);
            $uzsEquiv = abs($delta) * $rate;

            $breakdown[$currency] = [
                'delta'     => $delta,
                'rate'      => $rate,
                'uzs_equiv' => $uzsEquiv,
            ];

            $totalSeverity += $uzsEquiv;

            if ($stale) {
                $fxStale = true;
            }
        }

        $tier = $this->selectTier($totalSeverity, $reasonCap, $managerCap);

        // Stale FX is a conservative bump — never downgrade Blocked.
        if ($fxStale && $tier !== OverrideTier::Blocked && $tier !== OverrideTier::None) {
            $tier = OverrideTier::Manager;
        }

        return new ShiftCloseEvaluation(
            tier: $tier,
            severityUzs: round($totalSeverity, 2),
            perCurrencyBreakdown: $breakdown,
            fxStale: $fxStale,
        );
    }

    /**
     * @return array{0: float, 1: bool}  [rate, isStale]
     */
    private function rateFor(string $currency, int $stalenessDays): array
    {
        $currency = strtoupper($currency);

        if ($currency === 'UZS') {
            return [1.0, false];
        }

        $resolved = $this->rateResolver
            ? ($this->rateResolver)($currency)
            : $this->resolveFromDb($currency);

        $rate = (float) ($resolved['rate'] ?? 0);
        $date = $resolved['date'] ?? null;

        if ($rate <= 0) {
            // No rate at all — treat as stale; severity contribution still uses 0
            // which would mask real discrepancy. Use a fail-safe high rate so a
            // non-zero delta in this currency cannot be ignored.
            return [PHP_FLOAT_MAX, true];
        }

        $stale = $date === null
            ? true
            : $date->copy()->startOfDay()->diffInDays(today()) > $stalenessDays;

        return [$rate, $stale];
    }

    /**
     * @return array{rate: float|null, date: \Illuminate\Support\Carbon|null}
     */
    private function resolveFromDb(string $currency): array
    {
        $row = DailyExchangeRate::latest();

        if (! $row) {
            return ['rate' => null, 'date' => null];
        }

        $rate = match ($currency) {
            'USD' => (float) $row->usd_uzs_rate,
            'EUR' => (float) $row->eur_effective_rate,
            'RUB' => (float) $row->rub_effective_rate,
            default => null,
        };

        return ['rate' => $rate, 'date' => $row->rate_date];
    }

    private function selectTier(float $severity, float $reasonCap, float $managerCap): OverrideTier
    {
        if ($severity <= 0.0) {
            return OverrideTier::None;
        }

        if ($severity <= $reasonCap) {
            return OverrideTier::Cashier;
        }

        if ($severity <= $managerCap) {
            return OverrideTier::Manager;
        }

        return OverrideTier::Blocked;
    }
}
