<?php

namespace App\DTOs\Fx;

use App\Enums\Currency;
use App\Enums\OverrideTier;

/**
 * Result of evaluating how much a proposed payment deviates from the presented amount.
 */
final class OverrideEvaluation
{
    public function __construct(
        public readonly Currency     $currency,
        public readonly float        $presentedAmount,   // from snapshot
        public readonly float        $proposedAmount,    // what cashier entered
        public readonly float        $variancePct,       // absolute % difference
        public readonly bool         $withinTolerance,   // <= fx.tolerance_pct
        public readonly OverrideTier $tier,              // none | cashier | manager | blocked
    ) {}

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
     * Factory for split-leg / group-bulk legs where per-leg variance
     * evaluation is meaningless because the operator partitioned a
     * total — a single leg's amount is by definition not equal to the
     * full booking presented amount.
     *
     * The parent layer (recordSplitPayment / recordMixedCurrencySplitPayment
     * / recordBulkGroupPayment) is responsible for sum-lock at the
     * correct granularity. This factory yields a tier=None evaluation
     * so the leg row's audit columns honestly record "variance check
     * skipped because this is a split/bulk leg" rather than reporting
     * a false withinTolerance=true that came from a real evaluator
     * pass.
     */
    public static function skippedForSplit(
        Currency $currency,
        float    $presentedAmount,
        float    $proposedAmount,
    ): self {
        return new self(
            currency:        $currency,
            presentedAmount: $presentedAmount,
            proposedAmount:  $proposedAmount,
            variancePct:     0.0,
            withinTolerance: true,
            tier:            OverrideTier::None,
        );
    }
}
