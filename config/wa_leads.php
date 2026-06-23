<?php

declare(strict_types=1);

return [
    // Master gate. Default OFF. Detection (dry-run) is read-only either way.
    'ingestion_enabled' => (bool) env('WA_LEAD_INGESTION_ENABLED', false),

    'scan_days' => (int) env('WA_LEAD_SCAN_DAYS', 120),
    'scan_max'  => (int) env('WA_LEAD_SCAN_MAX', 500),

    // Classifier decision thresholds + the action gates (both default OFF).
    'autocreate_min_conf'  => (float) env('WA_LEAD_AUTOCREATE_MIN_CONF', 0.85),
    'autodismiss_min_conf' => (float) env('WA_LEAD_AUTODISMISS_MIN_CONF', 0.90),
    'auto_create_enabled'  => (bool) env('WA_LEAD_AUTO_CREATE_ENABLED', false),
    'auto_dismiss_enabled' => (bool) env('WA_LEAD_AUTO_DISMISS_ENABLED', false),

    // ONLY these not_lead subtypes may ever be auto-dismissed. accommodation /
    // logistics / personal / other are real (non-tour) people -> always review,
    // never silently dropped.
    'junk_subtypes' => ['spam', 'b2b', 'supplier'],

    // Deterministic hard-negative lexicon (seeded from real-data Phase 2a). A
    // message containing any of these is B2B/marketing/spam -> excluded BEFORE
    // the AI classifier (saves a call + is a junk subtype, dismiss-eligible).
    // Conservative: only phrases that are unambiguously non-traveler.
    'b2b_lexicon' => [
        'platform', 'partnership', 'partner with', 'co-founder', 'founder of', 'b2b',
        'affiliate', 'collaborat', 'grow your business', 'we offer', 'increase your',
        'our company offers', 'investment opportunity', 'crypto', 'bitcoin', 'casino',
        'wholesale', 'reseller', 'digital marketing', 'marketing agency', 'advertis',
        'sponsorship', 'guidemeet',
    ],

    // Read-only SSH to the wacli host (vps-main), command-locked to the candidate
    // scanner only (see /root/.wacli/wa-candidate-ssh.sh on that host).
    'ssh' => [
        'host'          => env('WA_LEAD_SSH_HOST', '62.72.22.205'),
        'port'          => (int) env('WA_LEAD_SSH_PORT', 2222),
        'user'          => env('WA_LEAD_SSH_USER', 'root'),
        'key'           => env('WA_LEAD_SSH_KEY', '/etc/jahongirnewapp/wa-candidate-key'),
        'known_hosts'   => env('WA_LEAD_SSH_KNOWN_HOSTS', '/etc/jahongirnewapp/known_hosts'),
        'remote_script' => env('WA_LEAD_REMOTE_SCRIPT', '/root/.wacli/wa-candidate-scan.py'),
        'timeout'       => (int) env('WA_LEAD_SSH_TIMEOUT', 90),
    ],
];
