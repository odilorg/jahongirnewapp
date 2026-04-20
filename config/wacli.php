<?php

declare(strict_types=1);

return [
    // Master gate. Matches the Zoho Mail pattern: code ships behind this flag
    // and only an explicit env flip lights up the scheduler.
    'lead_whatsapp_ingestion_enabled' => (bool) env('LEAD_WHATSAPP_INGESTION_ENABLED', false),

    'remote' => [
        // jahongir connects to vps-main via SSH and runs the restricted
        // wrapper /usr/local/bin/wacli-read-for-jahongir.sh.
        'host'        => env('WACLI_REMOTE_HOST', '62.72.22.205'),
        'port'        => (int) env('WACLI_REMOTE_PORT', 2222),
        'user'        => env('WACLI_REMOTE_USER', 'root'),
        'identity'    => env('WACLI_REMOTE_IDENTITY', '/root/.ssh/id_ed25519_wacli_read'),
        // Fixed command installed under authorized_keys command="..."; here
        // only as documentation — SSH ignores whatever client sends.
        'remote_cmd'  => '/usr/local/bin/wacli-read-for-jahongir.sh',
        'ssh_timeout' => (int) env('WACLI_SSH_TIMEOUT', 30),
    ],

    // How far back to look on the very first run (before lead_whatsapp_ingestions
    // has any rows to resume from). Kept small to avoid flooding the queue with
    // ancient conversations that were already handled out-of-band.
    'initial_lookback_hours' => (int) env('WACLI_INITIAL_LOOKBACK_HOURS', 12),

    // Max messages to parse per scheduler tick. The remote wrapper currently
    // returns up to 500; this caps local ingestion work per run.
    'batch_size' => (int) env('WACLI_BATCH_SIZE', 200),

    // Chat-level skips. Groups never produce 1:1 leads; LID-only chats have
    // no recoverable phone number so we park them for human review.
    'skip_group_chats'  => true,
    'skip_lid_only'     => true,

    // Substring check against lower-cased sender_phone / chat_jid / chat_name.
    // Extend via env, not code.
    'sender_blocklist' => [
        // known WA Business / system broadcasts live here once we see them
    ],

    // Your own WA numbers (digits only, no +). Used as a second-line defence
    // alongside the FromMe flag for detecting self messages.
    'self_numbers' => array_filter(array_map(
        'trim',
        explode(',', (string) env('WACLI_SELF_NUMBERS', '')),
    )),
];
