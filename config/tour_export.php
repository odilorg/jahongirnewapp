<?php

declare(strict_types=1);

/**
 * Tour catalog export — writes priced tours from Laravel DB to a generated
 * PHP data file on the same VPS that serves the static jahongir-travel.uz
 * site. See app/Services/TourCatalogExportService.php for the pipeline.
 *
 * This used to be an SSH push target from an earlier architecture sketch.
 * In production the static site lives on the same box as the Laravel app
 * (Jahongir VPS), so we skip the network hop entirely and write directly
 * to the local filesystem with an atomic temp+rename.
 */
return [
    // Absolute path of the static-site root on this VPS.
    'site_root' => env('TOUR_EXPORT_SITE_ROOT', '/domains/jahongir-travel.uz'),

    // Relative to site_root.
    'relative_data_path' => env('TOUR_EXPORT_DATA_PATH', 'data/tours.php'),

    // Where the export service stages the file before the atomic rename.
    // Staged inside the target data dir so rename() is guaranteed to be
    // same-filesystem and atomic.
    'staging_suffix' => '.tmp',

    // Schema version baked into the rendered PHP file. Bump if the
    // structure ever changes incompatibly so loaders can guard against it.
    'schema_version' => 1,

    // Hard timeout for any child process (php -l, etc), seconds.
    'process_timeout' => 15,

    // OTA commission rates (%). Editable here — no code deploy needed.
    // Used by GygInquiryWriter and future OTA writers.
    'ota_commission_rates' => [
        'gyg'    => (float) env('OTA_COMMISSION_GYG', 30),
        'viator' => (float) env('OTA_COMMISSION_VIATOR', 20),
    ],

    // Rollout tag used for backup filenames during 8.3b-2a rollout.
    // Backups are named <page>.php.<rollout_backup_tag>.
    'rollout_backup_tag' => env('TOUR_EXPORT_ROLLOUT_BACKUP_TAG', 'bak-pricing-loader-20260415'),

    // Global kill switch for the auto-export pipeline (Phase 8.3b-2b).
    // Defaults to true. Set TOUR_AUTO_EXPORT=false in .env to pause
    // observer-driven auto-exports without reverting code.
    'auto_export_enabled' => env('TOUR_AUTO_EXPORT', true),
];
