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

    // ── PDF datasheet exporter (TourPdfExportService) ──────────────
    // Root for PDF output — same VPS, same filesystem as the static
    // site so atomic temp+rename is safe. Each tour's pdf_relative_path
    // on the model is joined to this root.
    'pdf_output_root' => env('TOUR_EXPORT_PDF_ROOT', '/domains/jahongir-travel.uz'),

    // Sanity bound. Any rendered PDF smaller than this is treated as
    // broken; the exporter aborts the rename and keeps the existing
    // file intact. Tuned for dompdf — a valid itinerary PDF is 30–80 KB.
    'pdf_min_bytes' => 8000,

    // V1 is manual only. When observer auto-export learns to regenerate
    // PDFs too (Phase 2), flip this in .env without a code change.
    'pdf_auto_export_enabled' => env('TOUR_PDF_AUTO_EXPORT', false),

    // Contact block rendered in every PDF datasheet footer.
    // Edit here (no deploy needed — `php artisan config:clear` is
    // enough) then re-run tours:export-website-pdfs.
    'pdf_contact' => [
        'whatsapp' => env('TOUR_PDF_WHATSAPP', '+998 94 880 11 99'),
        'phone'    => env('TOUR_PDF_PHONE', null),
        'email'    => env('TOUR_PDF_EMAIL', 'info@jahongir-travel.uz'),
        'website'  => env('TOUR_PDF_WEBSITE', 'https://jahongir-travel.uz'),
    ],
];
