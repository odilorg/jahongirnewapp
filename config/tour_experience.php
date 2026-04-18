<?php

declare(strict_types=1);

/**
 * Phase 25 — Per-tour experience strings used by the day-before reminder.
 * Keep this file dumb: arrays of strings keyed by tour_product slug.
 * Fallback to 'default' when slug unknown.
 */
return [
    'packing_lists' => [
        'yurt-camp-tour' => [
            'Swimsuit (optional, if weather allows 🏊)',
            'Light jacket — desert evenings get cool',
            'Camera — sunset at Aydarkul 🌅',
            'Charged phone (power available, but a power bank is recommended)',
            'Water bottle (refill stops available)',
        ],
        'shahrisabz-day-trip' => [
            'Comfortable walking shoes',
            'Hat + sunscreen in summer',
            'Camera',
            'Water bottle',
        ],
        'nuratau-homestay-3-days' => [
            'Comfortable clothes for walking',
            'Light jacket — mountain evenings get cool',
            'Charged phone + power bank',
            'Camera',
            'Water bottle',
        ],
        'default' => [
            'Comfortable shoes',
            'Camera',
            'Water bottle',
        ],
    ],

    'meal_plans' => [
        'yurt-camp-tour'              => 'Meals included: lunch, dinner, and breakfast',
        'shahrisabz-day-trip'         => 'Meals: lunch at local restaurant (not included)',
        'nuratau-homestay-3-days'     => 'Meals included: all meals at the homestay',
        'default'                     => '',
    ],

    'weather_locations' => [
        'yurt-camp-tour'              => 'Aydarkul',
        'shahrisabz-day-trip'         => 'Shahrisabz',
        'nuratau-homestay-3-days'     => 'Nurata',
        'default'                     => null,
    ],

    'ops_whatsapp' => env('TOUR_OPS_WHATSAPP', '+998 91 555 08 08'),
];
