<?php

declare(strict_types=1);

namespace App\Services\Fx;

/**
 * Value object holding the 5 simple-FX columns to write on a
 * cash_transactions row. Phase 1 dual-write helper.
 *
 * The whole point: derive these fields from PRIMITIVES (rates +
 * currency + amounts), not from the legacy PaymentPresentation DTO.
 * The audit (docs/architecture/fx-simplification-plan.md Appendix A)
 * established that PaymentPresentation has two incompatible copies
 * with different field shapes; the new pipeline can't depend on
 * either.
 *
 * Phase 1 scope: UZS payments only. EUR/RUB-paid transactions leave
 * these columns NULL. Phase 2 will widen.
 */
final class SimpleFxFields
{
    public function __construct(
        public readonly ?float $referenceRate,
        public readonly ?float $actualRate,
        public readonly ?float $deviationPct,
        public readonly bool   $wasOverridden,
        public readonly ?string $overrideReason,
    ) {}

    /**
     * Empty value — used when a transaction is not FX-relevant
     * (non-payment categories, EUR/RUB-paid in Phase 1, no booking).
     */
    public static function empty(): self
    {
        return new self(null, null, null, false, null);
    }

    /**
     * Derive the five fields from a UZS-paid cashier-bot payment.
     *
     * Rate semantics: UZS per 1 USD.
     *   reference_rate = current USD→UZS from ExchangeRateService (or
     *                    a passed-in snapshot — Phase 2 will fetch fresh)
     *   actual_rate    = amount_paid_uzs / usd_equivalent_paid
     *   deviation_pct  = (actual_rate − reference_rate) / reference_rate × 100
     *
     * If usd_equivalent_paid is missing or zero, the row is non-FX
     * (e.g., a USD payment that didn't go through the rate engine);
     * return empty so columns stay NULL.
     */
    public static function deriveForUzsPayment(
        float $amountPaidUzs,
        ?float $usdEquivalentPaid,
        ?float $referenceRateUzsPerUsd,
        ?string $overrideReason,
    ): self {
        if ($usdEquivalentPaid === null || $usdEquivalentPaid <= 0.0) {
            return self::empty();
        }
        if ($referenceRateUzsPerUsd === null || $referenceRateUzsPerUsd <= 0.0) {
            return self::empty();
        }

        $actualRate = round($amountPaidUzs / $usdEquivalentPaid, 4);
        $deviationPct = round((($actualRate - $referenceRateUzsPerUsd) / $referenceRateUzsPerUsd) * 100, 4);
        $wasOverridden = abs($deviationPct) > 0.0001;
        $reason = $overrideReason !== null ? trim($overrideReason) : null;

        return new self(
            referenceRate: round($referenceRateUzsPerUsd, 4),
            actualRate: $actualRate,
            deviationPct: $deviationPct,
            wasOverridden: $wasOverridden,
            overrideReason: $reason !== '' ? $reason : null,
        );
    }

    /**
     * @return array<string, mixed> column => value, ready for
     *                              CashTransaction::update().
     */
    public function toArray(): array
    {
        return [
            'reference_rate'  => $this->referenceRate,
            'actual_rate'     => $this->actualRate,
            'deviation_pct'   => $this->deviationPct,
            'was_overridden'  => $this->wasOverridden,
            'override_reason' => $this->overrideReason,
        ];
    }
}
