<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Cashier — FX threshold guard
    |--------------------------------------------------------------------------
    |
    | Two thresholds drive the simplified FX policy (see
    | docs/architecture/fx-simplification-plan.md §1):
    |
    |   |deviation_pct| ≤ silent band    → record, no friction
    |   silent band < |dev| ≤ reason     → require override_reason
    |   |dev| > reason threshold (= reject) → throw InvalidFxOverrideException
    |
    | Defaults:
    |   - 3% silent band — generous enough for normal market jitter.
    |   - 15% hard block — past this, it's a typo or fraud, not an
    |     override.
    |
    | Both live in config so ops can adjust without a redeploy. Set
    | CASHIER_FX_OVERRIDE_REASON_REQUIRED_PCT and
    | CASHIER_FX_HARD_BLOCK_PCT in .env to override.
    */

    'fx' => [
        'override_reason_required_pct' => env('CASHIER_FX_OVERRIDE_REASON_REQUIRED_PCT', 3.0),
        'hard_block_pct'               => env('CASHIER_FX_HARD_BLOCK_PCT', 15.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cashier — Shift-close discrepancy escalation (C1)
    |--------------------------------------------------------------------------
    |
    | Severity = sum of absolute UZS-equivalent of (counted - expected) across
    | UZS / USD / EUR. Tiered against thresholds:
    |
    |   severity == 0                          → None    (close proceeds)
    |   0 < severity ≤ reason_threshold        → Cashier (reason required)
    |   reason < severity ≤ manager_threshold  → Manager (owner approves)
    |   severity > manager_threshold           → Blocked (Filament-only resolution)
    |
    | If FX rate older than fx_staleness_days, evaluator conservatively bumps
    | the tier to at least Manager (so close can't sneak through on stale math).
    |
    | C1.1 only adds the evaluator + DTO + columns. C1.3 wires the bot.
    */
    'shift_close' => [
        'reason_threshold_uzs'  => env('CASHIER_SHIFT_CLOSE_REASON_THRESHOLD_UZS',     100_000),
        'manager_threshold_uzs' => env('CASHIER_SHIFT_CLOSE_MANAGER_THRESHOLD_UZS',  1_000_000),
        'fx_staleness_days'     => env('CASHIER_SHIFT_CLOSE_FX_STALENESS_DAYS',            7),
    ],
];
