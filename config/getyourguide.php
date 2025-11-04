<?php

return [
    'imap' => [
        'host' => env('IMAP_HOST', 'imap.zoho.com'),
        'port' => env('IMAP_PORT', 993),
        'encryption' => env('IMAP_ENCRYPTION', 'ssl'),
        'username' => env('IMAP_USERNAME'),
        'password' => env('IMAP_PASSWORD'),
        'validate_cert' => env('IMAP_VALIDATE_CERT', true),
        'protocol' => env('IMAP_PROTOCOL', 'imap'),
    ],

    'email' => [
        'folder' => env('GYG_EMAIL_FOLDER', 'INBOX'),
        'from_patterns' => [
            'getyourguide',
            'noreply@mail.getyourguide.com',
            'booking@getyourguide.com',
            'notifications@getyourguide.com',
        ],
        'subject_patterns' => [
            'booking confirmation',
            'tour confirmation',
            'getyourguide booking',
            'your booking',
        ],
        'processed_folder' => env('GYG_PROCESSED_FOLDER', null), // null = don't move
    ],

    'processing' => [
        'check_interval_minutes' => env('GYG_CHECK_INTERVAL', 15),
        'max_emails_per_fetch' => 100,
        'max_ai_attempts' => 5,
        'job_timeout' => 120,
        'job_tries' => 3,
    ],

    'notifications' => [
        'enabled' => env('GYG_ENABLE_NOTIFICATIONS', true),
        'staff_chat_ids' => array_filter(explode(',', env('GYG_STAFF_TELEGRAM_CHAT_IDS', ''))),
    ],

    'timezone' => env('GYG_TIMEZONE', 'Asia/Samarkand'), // Uzbekistan/Samarkand timezone
];
