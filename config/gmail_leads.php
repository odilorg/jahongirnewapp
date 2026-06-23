<?php

declare(strict_types=1);

return [
    // Master gate. Default OFF — writes/scheduler stay inert until flipped.
    'ingestion_enabled' => (bool) env('GMAIL_LEAD_INGESTION_ENABLED', false),

    // himalaya account (already configured on host 161 for Viator/GYG).
    'himalaya_account' => env('GMAIL_LEAD_ACCOUNT', 'gmail'),

    // The ONLY Gmail label/folder we read. An operator Gmail filter populates it.
    'label'           => env('GMAIL_LEAD_LABEL', 'CRM-Leads'),
    'processed_label' => env('GMAIL_LEAD_PROCESSED_LABEL', 'CRM-Leads/Processed'),

    'batch_size' => (int) env('GMAIL_LEAD_BATCH_SIZE', 50),

    // OPTIONAL extra gate: if non-empty, a contact-form email must ALSO come
    // from one of these notifier senders to qualify. Empty = rely on the label
    // + the body template only. Comma-separated.
    'website_notifier_senders' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('GMAIL_LEAD_NOTIFIER_SENDERS', ''))
    ))),

    // Ingest direct (non-template) emails too? OFF by default — only the website
    // contact-form template qualifies in v1. The label filter is free-form's only
    // guard, so opt in deliberately once that filter is trusted.
    'free_form_enabled' => (bool) env('GMAIL_LEAD_FREEFORM_ENABLED', false),

    // Direct (free-form) emails from these senders are never ingested. Does NOT
    // apply to contact-form notifications (their sender is the website mailer).
    'sender_blocklist' => [
        'mailer-daemon@', 'postmaster@', 'bounce@', 'no-reply@', 'noreply@',
        'booking@t1.viator.com', 'noreply@getyourguide.com',
    ],
];
