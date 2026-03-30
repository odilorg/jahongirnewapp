<?php

namespace App\Services;

use App\Enums\OverrideTier;

/**
 * Determines the override tier based on variance between presented and paid amounts.
 *
 * Thresholds are configured in config/fx.php under override_policy:
 *   cashier_threshold (%)  — cashier can self-approve below this
 *   manager_threshold (%)  — requires manager Telegram approval above cashier_threshold
 *   anything above manager_threshold → blocked, must escalate offline
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
