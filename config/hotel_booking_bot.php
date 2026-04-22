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
];
