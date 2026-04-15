<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\TourCatalogExportService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Write the current tour pricing catalog to the static website data file.
 *
 *   php artisan tours:export-website-data
 *   php artisan tours:export-website-data --dry-run
 *   php artisan tours:export-website-data --dry-run --dump
 *   php artisan tours:export-website-data --only=yurt-camp-tour
 *
 * Pricing-only on purpose. See TourCatalogExportService docblock.
 */
class ExportToursWebsiteData extends Command
{
    protected $signature = 'tours:export-website-data
                            {--dry-run : Build + render + lint only, do not write the target file}
                            {--dump : With --dry-run, also print the rendered PHP to stdout}
                            {--only=* : Restrict export to specific tour slugs}';

    protected $description = 'Export tour pricing catalog to jahongir-travel.uz static site';

    public function handle(TourCatalogExportService $service): int
    {
        $only   = (array) $this->option('only');
        $dryRun = (bool) $this->option('dry-run');
        $dump   = (bool) $this->option('dump');

        $this->info($dryRun ? 'Dry run — building payload only.' : 'Exporting tour catalog to website...');

        $started = microtime(true);

        try {
            $result = $service->export($only ?: null, $dryRun);
        } catch (Throwable $e) {
            $this->error('Export failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $elapsed = number_format(microtime(true) - $started, 2);

        $this->line('  Tours exported: ' . $result['tours_count']);
        $this->line('  Bytes rendered: ' . $result['bytes']);
        $this->line('  Generated at:   ' . $result['generated_at']);
        $this->line('  Target path:    ' . $result['target_path']);

        if (! empty($result['skipped'])) {
            $this->warn('  Skipped (no price tiers): ' . implode(', ', $result['skipped']));
        }

        if ($dryRun) {
            $this->info("Dry run OK in {$elapsed}s. Nothing written.");

            if ($dump && isset($result['rendered_php'])) {
                $this->line('');
                $this->line('----- rendered PHP -----');
                $this->line($result['rendered_php']);
                $this->line('------------------------');
            }

            return self::SUCCESS;
        }

        $this->info("Written in {$elapsed}s.");

        return self::SUCCESS;
    }
}
