<?php

declare(strict_types=1);

/**
 * Phase 24 — Group Matching Engine config.
 */
return [
    // Max number of calendar days between two leads for them to be considered
    // a potential group cluster.
    'window_days' => (int) env('MATCHING_WINDOW_DAYS', 2),

    // Combined pax ceiling (driver seats / group tour capacity).
    'max_pax' => (int) env('MATCHING_MAX_PAX', 8),

    // Don't propose matches for tours too close (guest has no time to
    // decide). Matches with travel_date < today + min_days are hidden.
    'min_days_before_travel' => (int) env('MATCHING_MIN_DAYS', 2),

    // Group rate estimate per person. Used to compute combined revenue
    // at group price vs current private quotes. Operator can still
    // adjust in the actual message; this is a *suggestion* only.
    // Per tour_product slug for now — extend later if needed.
    'group_rate_per_person_usd' => [
        'yurt-camp-tour'              => 180,
        'shahrisabz-day-trip'         => 85,
        'aydar-lake-yurt-tour'        => 160,
        'nuratau-homestay-3-days'     => 240,
        'default'                     => 150,
    ],

    // Rough cost per group tour (same regardless of pax): fuel, driver,
    // guide, accommodation. Used to estimate margin on cluster.
    'estimated_group_cost_usd' => [
        'yurt-camp-tour'              => 250,
        'shahrisabz-day-trip'         => 120,
        'aydar-lake-yurt-tour'        => 240,
        'nuratau-homestay-3-days'     => 350,
        'default'                     => 200,
    ],
];
