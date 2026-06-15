<?php

declare(strict_types=1);

/**
 * Phase 25 — Per-tour experience strings used by the day-before reminder.
 * Keep this file dumb: arrays of strings keyed by tour_product slug.
 * Fallback to 'default' when slug unknown.
 */

// Phase 29 — shared guest experience copy for the 2-day Aydarkul / yurt
// camp tours (both catalogue slugs are the same physical experience).
// {name} is replaced with the guest's first name at send time. Plain text
// + emoji renders fine on WhatsApp; no *bold* markup used here.
$yurtExperienceMessages = [
    'post_pickup_welcome' => "Hi {name}! 👋\n\n"
        ."We hope you're enjoying the start of your journey.\n\n"
        ."During your trip, you can look forward to:\n\n"
        ."🏜 Aydarkul Lake\n"
        ."🐪 Camel riding\n"
        ."🏕 Traditional yurt camp\n"
        ."🌅 Desert sunset\n"
        ."🌌 Stargazing\n\n"
        ."If you need anything during the trip, simply reply to this message and our team will be happy to help.\n\n"
        ."Have a wonderful day!\n\n"
        .'— Jahongir Travel',

    'evening_sunset_tip' => "🌅 Sunset Alert\n\n"
        ."The desert sunset should begin soon and is often one of the most memorable moments of the trip.\n\n"
        ."📸 For the best photos:\n"
        ."• Include a camel, yurt, or person in the foreground\n"
        ."• Avoid zooming\n"
        ."• Take a few moments to simply enjoy the view\n\n"
        ."🌌 Tonight you may also see an incredible star-filled sky.\n\n"
        ."Enjoy your evening in the desert!\n\n"
        .'— Jahongir Travel',

    'next_morning_feedback' => "Good morning {name}! ☀️\n\n"
        ."We hope you enjoyed your evening at the yurt camp.\n\n"
        ."What has been your favorite part of the experience so far?\n\n"
        ."🐪 Camel ride\n"
        ."🌅 Sunset\n"
        ."🌌 Stargazing\n"
        ."🏕 Yurt camp\n"
        ."🌊 Aydarkul Lake\n\n"
        ."Or something else?\n\n"
        ."Your feedback helps us improve the experience for future guests.\n\n"
        .'— Jahongir Travel',
];

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
            'messages' => $yurtExperienceMessages,
        ],
        'bukhara-yurt-camp-samarkand' => [
            'day_count' => 2,
            'messages' => $yurtExperienceMessages,
        ],
    ],
];
