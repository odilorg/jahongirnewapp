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
    | Staleness — legacy key (kept for back-compat, no current consumer)
    |--------------------------------------------------------------------------
    | A booking_fx_syncs row is considered stale if its rate date is older
    | than today's published exchange rate date. This was the original
    | docblock intent. The actual `FxSyncService` code does not read this
    | config value today; the value remains here for any future consumer
    | and for backward-compat with environments that have FX_STALE_HOURS
    | set.
    |
    | Note: an earlier deploy attempt (2026-05-10, commit cb54bd2, rolled
    | back the same day) used this key with hourly-freshness semantics in
    | a guard at payment-session time. That broke normal afternoon
    | operations because the morning cron writes once a day at 07:00 —
    | by 11:00 the row was already past the 4h threshold. The guard's
    | re-implementation (FxStalenessGuard v2) now uses date-based
    | semantics with a separate secondary cap below.
    */
    'stale_after_hours' => (int) env('FX_STALE_HOURS', 4),

    /*
    |--------------------------------------------------------------------------
    | FxStalenessGuard — v2 semantics (added 2026-05-10)
    |--------------------------------------------------------------------------
    | The cashier-bot payment-session guard (FxStalenessGuard) enforces
    | TWO checks at session preparation time, both must pass:
    |
    |   PRIMARY (hardcoded, no config) — the latest
    |   `daily_exchange_rates.rate_date` must equal today's date in the
    |   app timezone. Catches the actual cron-failure mode: cron failed
    |   today => no row written for today => latest row is yesterday's =>
    |   refused.
    |
    |   SECONDARY (this key) — `fetched_at` not absurdly old. Catches
    |   the rare case where a row has today's rate_date but its
    |   fetched_at is from much earlier (data fix that backdated, system
    |   clock drift, etc.). Default 28 hours = one full daily cycle from
    |   any 07:00 cron run plus a 4h operational buffer.
    |
    | Values <= 0 clamp to 1 with a warning log to prevent a
    | misconfigured env silently disabling the guard or shifting the
    | threshold into the future. Source field (cbu / open.er-api /
    | floatrates / manual) is uniformly subject to both checks — no
    | per-source exemption.
    |
    | See `app/Services/Fx/FxStalenessGuard.php` for the full
    | per-check semantics + the rollback context.
    */
    'fresh_fetched_max_hours' => (int) env('FX_FRESH_FETCHED_MAX_HOURS', 28),

    /*
    |--------------------------------------------------------------------------
    | Beds24 infoItems version
    |--------------------------------------------------------------------------
    */
    'infoitems_version' => (int) env('FX_INFOITEMS_VERSION', 1),
];
