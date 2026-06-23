<?php

declare(strict_types=1);

return [
    // Master gate. Default OFF — writes/scheduler stay inert until flipped.
    'ingestion_enabled' => (bool) env('GMAIL_LEAD_INGESTION_ENABLED', false),

    // himalaya account (already configured on host 161 for Viator/GYG).
    'himalaya_account' => env('GMAIL_LEAD_ACCOUNT', 'gmail'),

    // SOURCE (Option B — default): read this folder, filtered SERVER-SIDE by the
    // sender query, so we read odilorg@gmail.com but only consider the contact-
    // form notifier — no Gmail label/filter setup required.
    //   folder       = mailbox folder to search (INBOX).
    //   sender_query = himalaya query appended to scope the listing.
    // Label mode is still possible: set folder to a label + sender_query to ''.
    'folder'       => env('GMAIL_LEAD_FOLDER', 'INBOX'),
    'sender_query' => env('GMAIL_LEAD_SENDER_QUERY', 'from info@jahongir-travel.uz'),

    // After a successful create, move the message out (label mode). OFF for
    // inbox mode — idempotency is the ledger, so we never touch the inbox.
    'move_processed'   => (bool) env('GMAIL_LEAD_MOVE_PROCESSED', false),
    'processed_folder' => env('GMAIL_LEAD_PROCESSED_FOLDER', 'CRM-Leads/Processed'),

    'batch_size' => (int) env('GMAIL_LEAD_BATCH_SIZE', 50),

    // Ingest direct (non-template) emails too? OFF by default — only the website
    // contact-form template qualifies in v1.
    'free_form_enabled' => (bool) env('GMAIL_LEAD_FREEFORM_ENABLED', false),

    // EXACT contact-form notifier sender(s). A contact-form email must come from
    // one of these (exact match) to qualify. Comma-separated. Defaults to the
    // confirmed website notifier.
    'website_notifier_senders' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('GMAIL_LEAD_NOTIFIER_SENDERS', 'info@jahongir-travel.uz'))
    ))),

    // Direct (free-form) emails from these senders are never ingested. Does NOT
    // apply to contact-form notifications.
    'sender_blocklist' => [
        'mailer-daemon@', 'postmaster@', 'bounce@', 'no-reply@', 'noreply@',
        'booking@t1.viator.com', 'noreply@getyourguide.com',
    ],
];
