<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Pdf\TourPdfExportService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Render downloadable tour datasheet PDFs for every PDF-enabled tour.
 *
 *   php artisan tours:export-website-pdfs
 *   php artisan tours:export-website-pdfs --dry-run
 *   php artisan tours:export-website-pdfs --only=samarkand-city-tour
 *   php artisan tours:export-website-pdfs --output-dir=/tmp/pdf-preview
 *
 * Writes to /domains/jahongir-travel.uz/<pdf_relative_path> unless
 * --output-dir is given. Per-tour failures are reported and never abort
 * the whole batch.
 */
class ExportToursWebsitePdfs extends Command
{
    protected $signature = 'tours:export-website-pdfs
                            {--dry-run : Render + sanity-check only, do not write final files}
                            {--only=* : Restrict to specific tour slugs}
                            {--output-dir= : Override output root (for previews). Defaults to config tour_export.pdf_output_root.}';

    protected $description = 'Render tour datasheet PDFs to the static site';

    public function handle(TourPdfExportService $service): int
    {
        $only      = (array) $this->option('only');
        $dryRun    = (bool) $this->option('dry-run');
        $outputDir = $this->option('output-dir') ?: null;

        $this->info($dryRun ? 'Dry run — rendering but not writing.' : 'Exporting tour PDFs...');

        if ($outputDir) {
            $this->line("  Output dir override: {$outputDir}");
        }

        $started = microtime(true);

        try {
            $result = $service->export($only ?: null, $dryRun, $outputDir);
        } catch (Throwable $e) {
            $this->error('Export aborted: ' . $e->getMessage());

            return self::FAILURE;
        }

        $elapsed = number_format(microtime(true) - $started, 2);

        $this->line('  Generated at: ' . $result['generated_at']);
        $this->line('  Exported:     ' . count($result['exported']));
        $this->line('  Skipped:      ' . count($result['skipped']));
        $this->line('  Failed:       ' . count($result['failed']));

        foreach ($result['exported'] as $row) {
            $this->line(sprintf(
                '    ✓ %-45s %6d bytes  hash=%s',
                $row['slug'],
                $row['bytes'],
                $row['hash']
            ));
        }

        foreach ($result['skipped'] as $row) {
            $this->warn(sprintf('    — %-45s skipped: %s', $row['slug'], $row['reason']));
        }

        foreach ($result['failed'] as $row) {
            $this->error(sprintf('    ✗ %-45s FAILED: %s', $row['slug'], $row['error']));
        }

        $this->info("Done in {$elapsed}s.");

        // Non-zero exit if anything failed — CI / cron can alert on it.
        return empty($result['failed']) ? self::SUCCESS : self::FAILURE;
    }
}
