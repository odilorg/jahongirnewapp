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

    // Phase 28 — guest reminder email fallback.
    // When a confirmed booking within the 24h window has no usable phone
    // but a valid email (typical for GYG/Viator OTA bookings whose guest
    // phone is never shared), send the tour-details reminder by email
    // instead of WhatsApp. Flag lets us disable the email channel instantly
    // without a redeploy if an OTA compliance concern surfaces; the WhatsApp
    // path and the no-contact operator alert are unaffected.
    'email_fallback_enabled' => (bool) env('TOUR_REMINDER_EMAIL_FALLBACK', true),

    // Phase 29 — guest experience message catalog.
    //
    // A tour_slug present here is OPTED IN to timed guest-care touchpoints.
    // Tours absent here receive none — this is the applicability gate (we do
    // NOT rely on tour_products.duration_days, which is mis-tagged for several
    // multi-day tours). Each slug declares its own day count so day-2+
    // messages are timed correctly regardless of the catalog column.
    //
    // 'messages' bodies are WhatsApp markup (*bold*, emoji, bare URLs); they
    // are sent verbatim over WhatsApp and converted to HTML if ever emailed.
    // {name} is replaced with the guest's first name at send time.
    'experience_messages' => [
        // 2-day overnight desert/yurt tours (both catalogue entries map to
        // the same physical experience).
        'yurt-camp-tour' => [
            'day_count' => 2,
            'messages' => [
                'post_pickup_welcome' => "Hi {name}! 👋 Welcome aboard — your desert adventure has begun 🐪\n\nToday: Aydarkul Lake, camel ride, and a night under the stars at the yurt camp. Sit back and enjoy the drive 🚙",
                'evening_sunset_tip'  => "Hi {name}! 🌅 Tonight, don't miss the sunset over Aydarkul — the colors are unreal. After dinner, walk a little away from the camp lights and look up: the stargazing here is some of the best in Uzbekistan ✨",
                'next_morning_feedback' => "Good morning {name}! ☀️ Hope you slept well under the desert sky.\n\nWhat's been your favorite part so far? We'd love to hear 😊",
            ],
        ],
        'bukhara-yurt-camp-samarkand' => [
            'day_count' => 2,
            'messages' => [
                'post_pickup_welcome' => "Hi {name}! 👋 Welcome aboard — your desert adventure has begun 🐪\n\nToday: Aydarkul Lake, camel ride, and a night under the stars at the yurt camp. Sit back and enjoy the drive 🚙",
                'evening_sunset_tip'  => "Hi {name}! 🌅 Tonight, don't miss the sunset over Aydarkul — the colors are unreal. After dinner, walk a little away from the camp lights and look up: the stargazing here is some of the best in Uzbekistan ✨",
                'next_morning_feedback' => "Good morning {name}! ☀️ Hope you slept well under the desert sky.\n\nWhat's been your favorite part so far? We'd love to hear 😊",
            ],
        ],
    ],
];
