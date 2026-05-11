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
        'hard_block_pct' => env('CASHIER_FX_HARD_BLOCK_PCT', 15.0),
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
        'reason_threshold_uzs' => env('CASHIER_SHIFT_CLOSE_REASON_THRESHOLD_UZS', 100_000),
        'manager_threshold_uzs' => env('CASHIER_SHIFT_CLOSE_MANAGER_THRESHOLD_UZS', 1_000_000),
        'fx_staleness_days' => env('CASHIER_SHIFT_CLOSE_FX_STALENESS_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Beds24 admin-cash → drawer-truth (Phase 1, 2026-05-11)
    |--------------------------------------------------------------------------
    | Closes the gap where a hotel admin records a cash payment in
    | Beds24 admin instead of via the cashier-bot: those rows are
    | source_trigger=beds24_external and were previously excluded
    | from scopeDrawerTruth, so the cashier saw a fake shortfall at
    | shift close. The webhook handler now evaluates five guards
    | (cash-method allow-list, after-cutoff, no-matching-bot-row,
    | open-shift, non-null booking_id) and sets
    | counts_as_drawer_truth=true ONLY when ALL pass.
    |
    | See `app/Services/CashierBot/Beds24ExternalPaymentMethodClassifier.php`
    | for the cash-method check and
    | `app/Http/Controllers/Beds24WebhookController.php` for full
    | guard evaluation.
    */
    'beds24_external_cash_methods' => [
        // Production-confirmed strings (30-day audit, 2026-05-11):
        //   karta → 35 rows (NOT cash, correctly excluded)
        //   naqd  → 10 rows (Uzbek "cash", SHOULD count as drawer truth)
        //   cash  →  3 rows (English, SHOULD count as drawer truth)
        //
        // Add new variants here as operators introduce them. Common
        // candidates (not yet seen in prod, kept commented for future):
        //   'нал'        (Russian shorthand for наличные)
        //   'наличные'   (Russian: cash)
        //   'налик'      (Russian slang)
        //
        // Matching is case-folded + trimmed via
        // Beds24ExternalPaymentMethodClassifier; the strings here are
        // compared as lowercase. Empty/null payment_method is treated
        // as NON-cash for the webhook path — Beds24 didn't tell us,
        // so we don't auto-trust.
        'cash',
        'naqd',
    ],

    /*
    | Flag-day cutoff. Beds24-external rows with `occurred_at` BEFORE
    | this timestamp are never auto-flagged drawer-truth, even if
    | every other guard passes. Prevents the deploy from retroactively
    | reclassifying historical balances. Manager can still flip an
    | older row manually via the Filament reconciliation page.
    |
    | Default ships as the Phase 1 deploy date. The env override
    | exists so an emergency rollback can move the cutoff into the
    | far future to disable the feature without dropping the column.
    */
    'beds24_admin_cash_drawer_truth_from' => env(
        'BEDS24_ADMIN_CASH_DRAWER_TRUTH_FROM',
        '2026-05-11 00:00:00',
    ),

    /*
    | Alert-only threshold for unusually large admin-cash entries.
    | When a beds24-external row passes all five drawer-truth guards
    | AND its USD amount exceeds this value, an owner alert fires
    | (the row STILL counts as drawer truth — no blocking, just
    | visibility). Catches fat-finger entries like 5_000_000 UZS
    | typed as 500_000 UZS in Beds24 admin.
    */
    'beds24_admin_cash_alert_threshold_usd' => (float) env(
        'BEDS24_ADMIN_CASH_ALERT_THRESHOLD_USD',
        200.0,
    ),
];
