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
        'shop_id' => env('OCTO_SHOP_ID'),
        'secret'  => env('OCTO_SECRET'),
        'url'     => env('OCTO_API_URL'),
        'tsp_id'  => env('OCTO_TSP_ID'),  // if needed
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
        'token' => env('TELEGRAM_BOT_TOKEN'),
        'webhook_url' => env('TELEGRAM_BOOKING_WEBHOOK_URL'),
        'session_timeout' => env('TELEGRAM_BOOKING_SESSION_TIMEOUT', 15), // minutes
        'secret_token' => env('TELEGRAM_BOOKING_SECRET_TOKEN'), // optional secret for webhook validation
    ],

    'beds24' => [
        'api_token' => env('BEDS24_API_TOKEN'),
        'api_v2_token' => env('BEDS24_API_V2_TOKEN'),
        'api_v2_refresh_token' => env('BEDS24_API_V2_REFRESH_TOKEN'),
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
        'expense_approval_threshold_uzs' => env('EXPENSE_APPROVAL_THRESHOLD', 500000),
    ],

    'housekeeping_bot' => [
        'token'          => env('HOUSEKEEPING_BOT_TOKEN', ''),
        'mgmt_group_id'  => env('HOUSEKEEPING_MGMT_GROUP_ID', ''),
        'webhook_secret' => env('HOUSEKEEPING_WEBHOOK_SECRET', ''),
    ],

    'kitchen_bot' => [
        'token'          => env('KITCHEN_BOT_TOKEN', ''),
        'webhook_secret' => env('KITCHEN_WEBHOOK_SECRET', ''),
    ],


    'gyg' => [
        'username'          => env('GYG_API_USERNAME', 'Jahongirtravel'),
        'password'          => env('GYG_API_PASSWORD'),
        'api_url'           => env('GYG_API_URL', 'https://supplier-api.getyourguide.com'),
        'email_override_to' => env('GYG_EMAIL_OVERRIDE_TO'),
    ],

];

