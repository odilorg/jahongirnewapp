<?php

declare(strict_types=1);

/**
 * Guest experience messaging engine (Phase 29).
 *
 * Timed guest-care touchpoints across a multi-day tour, separate from the
 * operational 24h pre-tour reminder (which stays in TourReminderDispatcher).
 *
 * Applicability is CATALOG-DRIVEN, not duration_days-driven: a tour only
 * receives experience messages if its tour_slug appears in
 * config('tour_experience.experience_messages'). This deliberately sidesteps
 * the unreliable tour_products.duration_days column (several multi-day tours
 * are mis-tagged as 1 day) — a tour is opted in by being catalogued, and the
 * catalog declares its own day structure.
 */
return [
    // Master kill switch. Ships OFF — the engine materializes nothing and
    // sends nothing until this is true. Flip on AFTER a dry-run verification.
    'enabled' => (bool) env('GUEST_EXPERIENCE_ENABLED', false),

    // A 'sending' row older than this (minutes) is treated as stale (the
    // previous send likely crashed) and swept to 'unknown' for manual review —
    // never auto-retried. Fail-closed: risk a missed message, never a dupe.
    'sending_stale_minutes' => (int) env('GUEST_EXPERIENCE_SENDING_STALE_MINUTES', 30),

    // A pending message more than this many minutes past its due_at is skipped
    // rather than sent late (a "welcome" a day after pickup is worse than none).
    'max_lateness_minutes' => (int) env('GUEST_EXPERIENCE_MAX_LATENESS_MINUTES', 720),

    // Sunset-tip timing. Desert sunset at Aydarkul swings ~17:15 (Dec) to
    // ~20:00 (Jun), so a fixed clock time would be wrong half the year. The
    // sunset_tip message fires `minutes_before` minutes ahead of the real
    // sunset for these coordinates on the tour's day-1 date (computed via
    // PHP date_sun_info — no external API). `fallback_time` is used only if
    // the sun calculation fails.
    'sunset' => [
        'lat' => (float) env('GUEST_EXPERIENCE_SUNSET_LAT', 40.70),   // Aydarkul yurt camps
        'lng' => (float) env('GUEST_EXPERIENCE_SUNSET_LNG', 65.60),
        'minutes_before' => (int) env('GUEST_EXPERIENCE_SUNSET_MINUTES_BEFORE', 40),
        'fallback_time' => env('GUEST_EXPERIENCE_SUNSET_FALLBACK', '18:30'),
    ],
];
