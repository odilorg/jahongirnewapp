<?php

declare(strict_types=1);

/**
 * Hotel booking Telegram bot (@j_booking_hotel_bot) — feature config.
 *
 * All flags default to safe/off. Production rollout requires explicitly
 * flipping HOTEL_BOT_PRICING_ENABLED (and usually
 * HOTEL_BOT_AUTO_COMPUTE_PRICE) in the environment. Back-out is
 * HOTEL_BOT_PRICING_ENABLED=false + config:cache — resolver returns a
 * no-charge result and payload omits invoiceItems.
 *
 * RoomUnitMapping has no currency column; auto-mode always uses
 * default_currency. Treat base_price values as stored in that currency.
 */
return [
    'pricing' => [
        'enabled' => env('HOTEL_BOT_PRICING_ENABLED', false),

        // When true, fall back to RoomUnitMapping.base_price if the
        // operator did not supply a price.
        'auto_compute_from_room_mapping' => env('HOTEL_BOT_AUTO_COMPUTE_PRICE', false),

        // When true, fail the booking if no price can be resolved
        // (manual or auto). When false, bookings without charge are
        // still created; confirmation will say "Charge: not added".
        'require_resolved_charge' => env('HOTEL_BOT_REQUIRE_RESOLVED_CHARGE', false),

        'default_currency'   => strtoupper((string) env('HOTEL_BOT_DEFAULT_CURRENCY', 'USD')),
        'allowed_currencies' => ['USD', 'UZS', 'EUR'],

        // Soft guard against runaway manual prices. Operator will be
        // told to confirm/adjust above this threshold (value is in the
        // operator-supplied or default currency; no FX applied).
        'max_price_per_night' => (float) env('HOTEL_BOT_MAX_PRICE_PER_NIGHT', 10000),

        'invoice_item_description' => 'Room charge',
    ],

    /*
    |--------------------------------------------------------------------------
    | Guest-forward confirmation (Phase 8.1 — operator copy-paste mode)
    |--------------------------------------------------------------------------
    | When enabled, after a successful single OR group booking the bot
    | sends a SECOND Telegram message to the operator containing a clean,
    | bilingual, plain-text confirmation the operator can forward verbatim
    | to the guest via WhatsApp / email / Telegram / SMS.
    |
    | Nothing is auto-sent to the guest in Phase 8.1. That comes in Phase 8.2
    | after operators confirm copy quality in real use.
    |
    | Property-specific values (address, maps link) live under
    | `properties.<propertyId>`; shared values (phone, WA, check-in/out
    | times) live under `defaults`. Override any string via .env — no code
    | deploy needed.
    */
    'guest_confirmation' => [
        'enabled' => env('HOTEL_BOT_GUEST_CONFIRMATION_ENABLED', false),

        'defaults' => [
            'phone'          => env('HOTEL_BOT_RECEPTION_PHONE', '+998 94 880 11 99'),
            'whatsapp'       => env('HOTEL_BOT_RECEPTION_WA', '+998 94 880 11 99'),
            'check_in_time'  => env('HOTEL_BOT_CHECK_IN_TIME', '14:00'),
            'check_out_time' => env('HOTEL_BOT_CHECK_OUT_TIME', '12:00'),
        ],

        // Keys are Beds24 property IDs (string).
        'properties' => [
            // Jahongir Hotel
            '41097' => [
                'name_en'    => env('HOTEL_BOT_HOTEL_NAME', 'Jahongir Hotel'),
                'address'    => env('HOTEL_BOT_HOTEL_ADDRESS', 'Samarkand, Uzbekistan'),
                'maps_link'  => env('HOTEL_BOT_HOTEL_MAPS', 'https://maps.google.com/?q=Jahongir+Hotel+Samarkand'),
            ],
            // Jahongir Premium
            '172793' => [
                'name_en'    => env('HOTEL_BOT_PREMIUM_NAME', 'Jahongir Premium'),
                'address'    => env('HOTEL_BOT_PREMIUM_ADDRESS', 'Samarkand, Uzbekistan'),
                'maps_link'  => env('HOTEL_BOT_PREMIUM_MAPS', 'https://maps.google.com/?q=Jahongir+Premium+Samarkand'),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | View-bookings limits (Phase 9 — date/range view)
    |--------------------------------------------------------------------------
    | max_range_days — soft cap on the width of a date range an operator
    |   may request via view-bookings ("bookings may 1-10"). Over the cap
    |   returns a "narrow your query" message without hitting Beds24.
    | max_rows        — cap on the number of bookings rendered in the
    |   reply. Overflow reads "+X more (narrow your query)".
    */
    'view' => [
        'max_range_days' => (int) env('HOTEL_BOT_VIEW_MAX_RANGE_DAYS', 31),
        'max_rows'       => (int) env('HOTEL_BOT_VIEW_MAX_ROWS', 30),
    ],
];
