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
}
