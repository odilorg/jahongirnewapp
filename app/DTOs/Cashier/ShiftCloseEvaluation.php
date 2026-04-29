<?php

declare(strict_types=1);

namespace App\DTOs\Cashier;

use App\Enums\OverrideTier;

/**
 * Result of classifying a shift-close discrepancy. Pure value object.
 *
 * Severity is the sum of |Δcurrency| × FX rate across all currencies, in UZS.
 * The tier is derived from severity vs config thresholds (see config/cashier.php
 * `shift_close.{reason,manager}_threshold_uzs`).
 *
 * `fxStale` flags that at least one currency's rate fell back beyond the
 * staleness cap; the evaluator conservatively bumps the tier to at least
 * Manager in that case so the close cannot pass on stale math.
 */
final class ShiftCloseEvaluation
{
    /**
     * @param array<string, array{delta: float, rate: float, uzs_equiv: float}> $perCurrencyBreakdown
     */
    public function __construct(
        public readonly OverrideTier $tier,
        public readonly float        $severityUzs,
        public readonly array        $perCurrencyBreakdown,
        public readonly bool         $fxStale,
    ) {}

    public function requiresReason(): bool
    {
        return $this->tier->requiresReason();
    }

    public function requiresApproval(): bool
    {
        return $this->tier->requiresApproval();
    }

    public function isBlocked(): bool
    {
        return $this->tier->isBlocked();
    }

    public function canProceed(): bool
    {
        return $this->tier->canProceed();
    }

    /**
     * Compact representation for logging / persistence audit.
     */
    public function toArray(): array
    {
        return [
            'tier'         => $this->tier->value,
            'severity_uzs' => $this->severityUzs,
            'fx_stale'     => $this->fxStale,
            'breakdown'    => $this->perCurrencyBreakdown,
        ];
    }
}
