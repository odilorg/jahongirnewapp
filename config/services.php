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
    ],

    'telegram_bot' => [
    'beds24' => [
        'api_token' => env('BEDS24_API_TOKEN'),
        'api_v2_token' => env('BEDS24_API_V2_TOKEN'),
        'api_v2_refresh_token' => env('BEDS24_API_V2_REFRESH_TOKEN'),
    ],
        'token' => env('TELEGRAM_BOT_TOKEN'),
        'bot_token' => env('TELEGRAM_BOT_TOKEN_DRIVER_GUIDE'), 
        // Bot token from the .env file
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

];
