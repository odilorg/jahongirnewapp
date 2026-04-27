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
];
