<?php

return [

    /*
    |--------------------------------------------------------------------------
    | InfoItems Version
    |--------------------------------------------------------------------------
    |
    | Increment this when the infoItem keys or format change. FxSyncService
    | uses it in isStale() to force a re-push after a format change.
    |
    */
    'infoitems_version' => env('FX_INFOITEMS_VERSION', 1),

    /*
    |--------------------------------------------------------------------------
    | Override Policy Thresholds
    |--------------------------------------------------------------------------
    |
    | Controls how much a cashier can deviate from the presented FX amount.
    |
    | cashier_threshold: variance below this % → OverrideTier::Cashier
    |                    (cashier can self-approve with a reason)
    |
    | manager_threshold: variance between cashier_threshold and this % →
    |                    OverrideTier::Manager (requires manager Telegram approval)
    |
    | Variance above manager_threshold → OverrideTier::Blocked
    |                    (must escalate offline)
    |
    */
    'override_policy' => [
        'cashier_threshold' => env('FX_CASHIER_THRESHOLD', 2.0),   // percent
        'manager_threshold' => env('FX_MANAGER_THRESHOLD', 10.0),  // percent
    ],

];
