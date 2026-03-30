<?php

namespace App\Services\Fx;

use App\DTOs\Fx\OverrideEvaluation;
use App\Enums\Currency;
use App\Enums\OverrideTier;

/**
 * Pure value-object service: determines how much a proposed amount deviates
 * from the presented amount and what tier of override is required.
 *
 * No DB access — purely config-driven calculation.
 */
class OverridePolicyEvaluator
{
    private float $tolerancePct;
    private float $cashierThresholdPct;
    private float $managerThresholdPct;

    public function __construct()
    {
        $this->tolerancePct        = (float) config('fx.tolerance_pct', 0.5);
        $this->cashierThresholdPct = (float) config('fx.cashier_threshold_pct', 2.0);
        $this->managerThresholdPct = (float) config('fx.manager_threshold_pct', 10.0);
    }

    /**
     * Evaluate the variance between what was presented and what the cashier proposes.
     *
     * @param  Currency   $currency
     * @param  float|int  $presentedAmount  Amount shown in bot (from snapshot)
     * @param  float|int  $proposedAmount   Amount the cashier entered
     */
    public function evaluate(
        Currency   $currency,
        float|int  $presentedAmount,
        float|int  $proposedAmount,
    ): OverrideEvaluation {
        // Variance as absolute percentage, guarded against zero presented amount
        $variancePct = $presentedAmount > 0
            ? abs(($proposedAmount - $presentedAmount) / $presentedAmount) * 100
            : 0.0;

        $withinTolerance = $variancePct <= $this->tolerancePct;

        $tier = $this->resolveTier($variancePct, $withinTolerance);

        return new OverrideEvaluation(
            currency:        $currency,
            presentedAmount: (float) $presentedAmount,
            proposedAmount:  (float) $proposedAmount,
            variancePct:     round($variancePct, 4),
            withinTolerance: $withinTolerance,
            tier:            $tier,
        );
    }

    private function resolveTier(float $variancePct, bool $withinTolerance): OverrideTier
    {
        if ($withinTolerance) {
            return OverrideTier::None;
        }

        if ($variancePct <= $this->cashierThresholdPct) {
            // Still very close — cashier can self-approve with a reason
            return OverrideTier::Cashier;
        }

        if ($variancePct <= $this->managerThresholdPct) {
            return OverrideTier::Manager;
        }

        return OverrideTier::Blocked;
    }
}
