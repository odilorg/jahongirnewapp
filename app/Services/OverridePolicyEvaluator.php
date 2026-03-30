<?php

namespace App\Services;

use App\Enums\OverrideTier;

/**
 * @deprecated Use App\Services\Fx\OverridePolicyEvaluator instead.
 *             This class: (1) never returns OverrideTier::Blocked, (2) uses stale config
 *             keys (fx.override_policy.*) that differ from the canonical fx.* keys.
 *             CashierBotController was updated on 2026-03-30 to inject the Fx version.
 *             This class is retained only to avoid breaking any remaining bindings.
 *             TODO(payment-orchestrator): delete once confirmed unused.
 */
class OverridePolicyEvaluator
{
    public function evaluate(float $presented, float $paid): OverrideTier
    {
        if ($presented <= 0 || abs($presented - $paid) < 0.01) {
            return OverrideTier::None;
        }

        $variancePct = abs($paid - $presented) / $presented * 100;

        $cashierThreshold = (float) config('fx.override_policy.cashier_threshold', 2);
        $managerThreshold = (float) config('fx.override_policy.manager_threshold', 10);

        return match (true) {
            $variancePct <= $cashierThreshold => OverrideTier::None,     // within tolerance
            $variancePct <= $managerThreshold => OverrideTier::Cashier,  // self-approve
            default                           => OverrideTier::Manager,  // needs manager
            // Note: Blocked tier is not used here — caller decides if manager threshold
            // should block instead. Adjust thresholds in config to change behaviour.
        };
    }

    public function variancePct(float $presented, float $paid): float
    {
        if ($presented <= 0) return 0.0;
        return round(abs($paid - $presented) / $presented * 100, 2);
    }
}
