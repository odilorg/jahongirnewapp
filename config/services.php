<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */


    'octo' => [
        'shop_id'              => env('OCTO_SHOP_ID'),
        'secret'               => env('OCTO_SECRET'),
        'url'                  => env('OCTO_API_URL'),
        'tsp_id'               => env('OCTO_TSP_ID'),
        'fallback_usd_uzs_rate' => env('OCTO_FALLBACK_USD_UZS_RATE', 12100),

        // CF-proxied relay on vps-main (Germany) used as a fallback when
        // the direct Octo call from Jahongir VPS times out — Uzbek ISP
        // routing between 161.97.129.31 and secure.octo.uz drops
        // periodically. Relay fronts the same /prepare_payment endpoint
        // and is gated by CF-level IP allowlist + shared secret header.
        'relay_url'    => env('OCTO_RELAY_URL'),
        'relay_secret' => env('OCTO_RELAY_SECRET'),

        // Phase S — callback signature verification.
        // Formula (from help.octo.uz/en/notifications):
        //   SHA1( unique_key + octo_payment_UUID + status )
        // unique_key is a SEPARATE secret from OCTO technical team —
        // NOT octo_secret. Find it at merchant.octo.uz → Integration settings,
        // or request from Octo support for shop_id 27061.
        'unique_key'                => env('OCTO_UNIQUE_KEY'),
        'verify_callback_signature' => (bool) env('OCTO_VERIFY_CALLBACK_SIGNATURE', false),
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'webhook_secret' => env('TELEGRAM_MAIN_WEBHOOK_SECRET', ''),
    ],

    'telegram_bot' => [
        'token' => env('TELEGRAM_BOT_TOKEN'),
        'bot_token' => env('TELEGRAM_BOT_TOKEN_DRIVER_GUIDE'),
    ],

    'telegram_pos_bot' => [
        'token' => env('TELEGRAM_POS_BOT_TOKEN'),
        'webhook_url' => env('TELEGRAM_POS_WEBHOOK_URL'),
        'session_timeout' => env('TELEGRAM_POS_SESSION_TIMEOUT', 480), // minutes
        'secret_token' => env('TELEGRAM_POS_SECRET_TOKEN'), // optional secret for webhook validation
    ],

    'telegram_booking_bot' => [
        'token' => env('TELEGRAM_HOTEL_BOOKING_BOT_TOKEN'),
        'webhook_url' => env('TELEGRAM_BOOKING_WEBHOOK_URL'),
        'session_timeout' => env('TELEGRAM_BOOKING_SESSION_TIMEOUT', 15), // minutes
        'secret_token' => env('TELEGRAM_BOOKING_SECRET_TOKEN'), // optional secret for webhook validation
    ],

    'beds24' => [
        'api_token'             => env('BEDS24_API_TOKEN'),
        'api_v2_token'          => env('BEDS24_API_V2_TOKEN'),
        'api_v2_refresh_token'  => env('BEDS24_API_V2_REFRESH_TOKEN'),
        // Comma-separated property IDs whose room maps are warmed after token refresh.
        // Add a new property here when onboarding additional hotels.
        'room_map_properties'   => array_map(
            'intval',
            array_filter(explode(',', env('BEDS24_ROOM_MAP_PROPERTIES', '41097,172793')))
        ),
    ],

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],


    'owner_alert_bot' => [
        'token'          => env('OWNER_ALERT_BOT_TOKEN', ''),
        'owner_chat_id'  => env('OWNER_TELEGRAM_ID', ''),
        'webhook_secret' => env('OWNER_ALERT_WEBHOOK_SECRET', ''),
    ],

    'driver_guide_bot' => [
        'token'          => env('TELEGRAM_BOT_TOKEN_DRIVER_GUIDE', ''),
        'webhook_secret' => env('DRIVER_GUIDE_WEBHOOK_SECRET', ''),
        'owner_chat_id'  => env('TELEGRAM_OWNER_CHAT_ID', '38738713'),
    ],



    'cashier_bot' => [
        'token' => env('CASHIER_BOT_TOKEN', ''),
        'webhook_secret' => env('CASHIER_BOT_WEBHOOK_SECRET', ''),
        // Master switch — when false (default), cashier bot expenses go
        // "straight through" with no owner approval ping regardless of
        // amount. The approval flow stamps approved_at/rejected_at on
        // CashExpense for audit but does NOT block, reverse, or modify
        // the amount. Set CASHIER_EXPENSE_APPROVAL=true to re-enable
        // owner pings above the per-currency thresholds below.
        'expense_approval_enabled' => filter_var(env('CASHIER_EXPENSE_APPROVAL', false), FILTER_VALIDATE_BOOLEAN),
        'expense_approval_threshold_uzs' => env('EXPENSE_APPROVAL_THRESHOLD', 500000),
        'expense_approval_threshold_usd' => env('EXPENSE_APPROVAL_THRESHOLD_USD', 40),
        'expense_approval_threshold_eur' => env('EXPENSE_APPROVAL_THRESHOLD_EUR', 35),
        'expense_approval_threshold_rub' => env('EXPENSE_APPROVAL_THRESHOLD_RUB', 4000),
        // Default arrival-date range for the payment quick-pick list.
        // Manual booking-ID entry remains available for any date regardless.
        'payment_arrival_days_back'      => (int) env('CASHIER_PAYMENT_ARRIVAL_DAYS_BACK', 3),
        'payment_arrival_days_forward'   => (int) env('CASHIER_PAYMENT_ARRIVAL_DAYS_FORWARD', 14),
    ],

    'housekeeping_bot' => [
        'token'                  => env('HOUSEKEEPING_BOT_TOKEN', ''),
        'mgmt_group_id'          => env('HOUSEKEEPING_MGMT_GROUP_ID', ''),
        'premium_mgmt_group_id'  => env('HOUSEKEEPING_PREMIUM_MGMT_GROUP_ID', ''),
        'webhook_secret'         => env('HOUSEKEEPING_WEBHOOK_SECRET', ''),
    ],

    'kitchen_bot' => [
        'token'           => env('KITCHEN_BOT_TOKEN', ''),
        'webhook_secret'  => env('KITCHEN_WEBHOOK_SECRET', ''),
        'session_timeout' => env('KITCHEN_SESSION_TIMEOUT', 480), // minutes (8h shift)
    ],

    // Operator-only bot for manual tour booking entry (/newbooking command)
    'ops_bot' => [
        'token'          => env('OPS_BOT_TOKEN', ''),
        'webhook_secret' => env('OPS_BOT_WEBHOOK_SECRET', ''),
        'owner_chat_id'  => env('TELEGRAM_OWNER_CHAT_ID', '38738713'),
    ],

    // Static API key used by mailer-tours.php to authenticate website booking submissions
    'website_booking_api_key' => env('WEBSITE_BOOKING_API_KEY'),

    // tg-direct: Telegram DM service running on vps-main (127.0.0.1:8766)
    // reached locally via the systemd-managed autossh reverse tunnel
    // (tg-direct-tunnel.service on jahongir VPS).
    'tg_direct' => [
        'url'     => env('TG_DIRECT_URL', 'http://127.0.0.1:8766'),
        'timeout' => (int) env('TG_DIRECT_TIMEOUT', 5),
        'enabled' => (bool) env('TG_DIRECT_ENABLED', true),
    ],

    'gyg' => [
        'username'          => env('GYG_API_USERNAME', 'Jahongirtravel'),
        'password'          => env('GYG_API_PASSWORD'),
        'api_url'           => env('GYG_API_URL', 'https://supplier-api.getyourguide.com'),
        'email_override_to' => env('GYG_EMAIL_OVERRIDE_TO'),
        'wa_api_url'        => env('GYG_WA_API_URL', 'http://127.0.0.1:8080/api/send'),
    ],

    // Phase 22 — daily operator recap email destination
    'daily_recap' => [
        'email' => env('DAILY_RECAP_EMAIL', 'odilorg@gmail.com'),
    ],

];

