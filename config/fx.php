<?php

return [
    /*
    |--------------------------------------------------------------------------
    | FX Settlement Tolerance
    |--------------------------------------------------------------------------
    | Variance within this percentage is auto-accepted with no escalation.
    | Stored as within_tolerance=true, override_tier=none on the transaction.
    */
    'tolerance_pct' => (float) env('FX_TOLERANCE_PCT', 0.5),

    /*
    |--------------------------------------------------------------------------
    | Override Thresholds
    |--------------------------------------------------------------------------
    | cashier_threshold_pct: cashier can self-approve with a reason
    | manager_threshold_pct: async manager approval required
    | Beyond manager threshold: blocked entirely
    */
    'cashier_threshold_pct' => (float) env('FX_CASHIER_THRESHOLD_PCT', 2.0),
    'manager_threshold_pct' => (float) env('FX_MANAGER_THRESHOLD_PCT', 10.0),

    /*
    |--------------------------------------------------------------------------
    | Session TTL
    |--------------------------------------------------------------------------
    | How long a PaymentPresentation stays valid before the cashier must restart.
    */
    'presentation_ttl_minutes' => (int) env('FX_PRESENTATION_TTL', 30),

    /*
    |--------------------------------------------------------------------------
    | Manager Approval TTL
    |--------------------------------------------------------------------------
    */
    'manager_approval_ttl_minutes' => (int) env('FX_MANAGER_APPROVAL_TTL', 30),

    /*
    |--------------------------------------------------------------------------
    | Print Push Requirement
    |--------------------------------------------------------------------------
    | For near-term bookings (arrival within this many days), block print if
    | the Beds24 infoItems push has permanently failed.
    | Far-future bookings proceed with a warning only.
    */
    'print_require_push_within_days' => (int) env('FX_PRINT_PUSH_REQUIRED_DAYS', 2),

    /*
    |--------------------------------------------------------------------------
    | Staleness
    |--------------------------------------------------------------------------
    | A booking_fx_syncs row is considered stale if the rate date is older
    | than today's published exchange rate date.
    */
    'stale_after_hours' => (int) env('FX_STALE_HOURS', 4),

    /*
    |--------------------------------------------------------------------------
    | Beds24 infoItems version
    |--------------------------------------------------------------------------
    */
    'infoitems_version' => (int) env('FX_INFOITEMS_VERSION', 1),
];
