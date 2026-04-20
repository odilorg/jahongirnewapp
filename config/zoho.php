<?php

declare(strict_types=1);

return [
    // Master switch for Zoho Mail inbound ingestion. The scheduler will refuse
    // to fire leads:fetch-zoho-emails unless this is true — lets us ship code
    // dark and flip the flag manually only when operators are ready.
    'lead_email_ingestion_enabled' => (bool) env('LEAD_EMAIL_INGESTION_ENABLED', false),

    'mail_inbound' => [
        'host'             => env('MAIL_ZOHO_INBOUND_HOST', 'imap.zoho.com'),
        'port'             => (int) env('MAIL_ZOHO_INBOUND_PORT', 993),
        'encryption'       => env('MAIL_ZOHO_INBOUND_ENCRYPTION', 'ssl'),
        'validate_cert'    => (bool) env('MAIL_ZOHO_INBOUND_VALIDATE_CERT', true),
        'username'         => env('MAIL_ZOHO_INBOUND_USERNAME'),
        'password'         => env('MAIL_ZOHO_INBOUND_PASSWORD'),
        'inbox_folder'     => env('MAIL_ZOHO_INBOUND_FOLDER', 'INBOX'),
        'processed_folder' => env('MAIL_ZOHO_INBOUND_PROCESSED_FOLDER', 'Processed'),
        'batch_size'       => (int) env('MAIL_ZOHO_INBOUND_BATCH', 25),

        // Substring matched against the lower-cased sender address. Anything
        // containing one of these is skipped and marked Processed so it leaves
        // INBOX. Add more via config, not code.
        'sender_blocklist' => [
            'mailer-daemon@',
            'noreply@',
            'no-reply@',
            'donotreply@',
            'postmaster@',
            'bounce@',
            'delivery-failure@',
            'auto-reply@',
        ],
    ],
];
