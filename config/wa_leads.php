<?php

declare(strict_types=1);

return [
    // Master gate. Default OFF. Detection (dry-run) is read-only either way.
    'ingestion_enabled' => (bool) env('WA_LEAD_INGESTION_ENABLED', false),

    'scan_days' => (int) env('WA_LEAD_SCAN_DAYS', 120),
    'scan_max'  => (int) env('WA_LEAD_SCAN_MAX', 500),

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
