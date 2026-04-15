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
];
