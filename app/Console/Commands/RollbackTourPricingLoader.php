<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Restore static-site tour pages from their pricing-loader backups.
 *
 *   php artisan tours:rollback-pricing-loader --slug=yurt-camp-tour
 *   php artisan tours:rollback-pricing-loader --all
 *
 * Looks for files matching <original>.<rollout_backup_tag> next to the
 * live page, copies them back over the live file. Idempotent.
 */
class RollbackTourPricingLoader extends Command
{
    protected $signature = 'tours:rollback-pricing-loader
                            {--slug= : Restore a single slug}
                            {--all : Restore every backup found under the site root}';

    protected $description = 'Restore tour pages from pricing-loader backups';

    public function handle(): int
    {
        $root = rtrim((string) config('tour_export.site_root'), '/');
        $tag  = (string) config('tour_export.rollout_backup_tag', 'bak-pricing-loader-' . date('Ymd'));

        $slug = $this->option('slug');
        $all  = (bool) $this->option('all');

        if (! $slug && ! $all) {
            $this->error('Specify --slug=... or --all');

            return self::FAILURE;
        }

        $backups = $this->findBackups($root, $tag);
        if ($slug) {
            $backups = array_filter($backups, fn ($b) => str_ends_with(basename($b, '.' . $tag), "{$slug}.php"));
        }

        if ($backups === []) {
            $this->warn('No matching backups found.');

            return self::SUCCESS;
        }

        $restored = 0;
        foreach ($backups as $backup) {
            $live = substr($backup, 0, -strlen('.' . $tag));
            if (! is_file($live)) {
                $this->warn("  live file missing for {$backup}");
                continue;
            }
            if (! copy($backup, $live)) {
                $this->error("  restore failed: {$live}");
                continue;
            }
            $this->line("  restored: {$live}");
            $restored++;
        }

        $this->info("Restored {$restored} file(s).");

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function findBackups(string $root, string $tag): array
    {
        $cmd = sprintf('find %s -name %s 2>/dev/null', escapeshellarg($root), escapeshellarg('*.' . $tag));
        exec($cmd, $out);

        return $out ?: [];
    }
}
