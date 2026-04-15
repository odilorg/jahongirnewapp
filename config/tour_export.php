<?php

declare(strict_types=1);

/**
 * Tour catalog export — pushes priced tours from Laravel DB to the static
 * jahongir-travel.uz site as a generated PHP data file. See
 * app/Services/TourCatalogExportService.php for the pipeline.
 */
return [
    'ssh' => [
        'host' => env('TOUR_EXPORT_SSH_HOST', '95.46.96.14'),
        'user' => env('TOUR_EXPORT_SSH_USER', 'orienttr'),
        'port' => (int) env('TOUR_EXPORT_SSH_PORT', 22),
        'key'  => env('TOUR_EXPORT_SSH_KEY', storage_path('app/ssh/jahongir_travel_deploy')),
    ],

    'remote' => [
        'dir'  => env('TOUR_EXPORT_REMOTE_DIR', 'domains/jahongir-travel.uz/data'),
        'file' => env('TOUR_EXPORT_REMOTE_FILE', 'tours.php'),
    ],

    // Where the export service stages the file locally before scp.
    'local_temp_dir' => storage_path('app/tours-export'),

    // Schema version baked into the rendered PHP file. Bump if the
    // structure ever changes incompatibly so loaders can guard against it.
    'schema_version' => 1,

    // Hard timeout for any single ssh/scp child process, in seconds.
    'process_timeout' => 30,
];
