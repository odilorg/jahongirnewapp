<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\TourCatalogExportService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Push the current tour pricing catalog to the static website.
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
                            {--dry-run : Build + render + lint only, do not push to remote}
                            {--dump : With --dry-run, also print the rendered PHP to stdout}
                            {--only=* : Restrict export to specific tour slugs}';

    protected $description = 'Export tour pricing catalog to jahongir-travel.uz static site';

    public function handle(TourCatalogExportService $service): int
    {
        $only    = (array) $this->option('only');
        $dryRun  = (bool) $this->option('dry-run');
        $dump    = (bool) $this->option('dump');

        $keyPath = (string) config('tour_export.ssh.key');
        if (! $dryRun && ! is_file($keyPath)) {
            $this->printKeyMissingInstructions($keyPath);

            return self::FAILURE;
        }

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
        $this->line('  Remote path:    ~/' . $result['remote_path']);

        if (! empty($result['skipped'])) {
            $this->warn('  Skipped (no price tiers): ' . implode(', ', $result['skipped']));
        }

        if ($dryRun) {
            $this->info("Dry run OK in {$elapsed}s. Nothing pushed.");

            if ($dump && isset($result['rendered_php'])) {
                $this->line('');
                $this->line('----- rendered PHP -----');
                $this->line($result['rendered_php']);
                $this->line('------------------------');
            }

            return self::SUCCESS;
        }

        $this->info("Pushed in {$elapsed}s.");

        return self::SUCCESS;
    }

    private function printKeyMissingInstructions(string $keyPath): void
    {
        $this->error("SSH key not found at {$keyPath}");
        $this->line('');
        $this->line('To provision the export key on this VPS:');
        $this->line('');
        $this->line("  mkdir -p " . dirname($keyPath));
        $this->line("  ssh-keygen -t ed25519 -f {$keyPath} -N '' -C 'jahongirnewapp-tour-export'");
        $this->line("  chmod 600 {$keyPath}");
        $this->line('');
        $this->line('Then copy the PUBLIC key to the shared host:');
        $this->line('');
        $this->line("  cat {$keyPath}.pub");
        $this->line('  # append the printed line to orienttr@95.46.96.14:~/.ssh/authorized_keys');
        $this->line('');
        $this->line('Then test the connection:');
        $this->line('');
        $this->line("  ssh -i {$keyPath} orienttr@95.46.96.14 \"echo OK\"");
        $this->line('');
    }
}
