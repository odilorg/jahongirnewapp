<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TourProduct;
use App\Services\StaticSitePageCache;
use App\Support\StaticSiteEditor;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Roll out the pricing loader to static-site tour pages in safe waves.
 *
 *   php artisan tours:rollout-pricing-loader --wave=1 --dry-run
 *   php artisan tours:rollout-pricing-loader --wave=1
 *   php artisan tours:rollout-pricing-loader --slug=yurt-camp-tour
 *
 * See app/Support/StaticSiteEditor.php for the pure string-transform
 * primitives. This command only wraps them with filesystem IO, backup
 * bookkeeping, and a per-wave execution plan.
 */
class RolloutTourPricingLoader extends Command
{
    protected $signature = 'tours:rollout-pricing-loader
                            {--wave= : Run wave 1, 2, or 3}
                            {--slug= : Convert a single slug, bypassing waves}
                            {--dry-run : Report only, do not write}';

    protected $description = 'Roll out the tour pricing loader to static-site pages';

    /**
     * Wave plan. Each entry: [slug, file path relative to site_root, direction, type].
     *
     * @var array<int, array<int, array{0:string, 1:string, 2:string, 3:string}>>
     */
    private const WAVES = [
        1 => [
            ['nuratau-homestay-2-days', 'tours-from-samarkand/nuratau-homestay-2-days.php', 'default', 'private'],
            ['nuratau-homestay-4-days', 'tours-from-samarkand/nuratau-homestay-4-days.php', 'default', 'private'],
            ['bukhara-city-tour',       'tours-from-bukhara/bukhara-city-tour.php',         'default', 'private'],
        ],
        2 => [
            ['hiking-amankutan',                  'tours-from-samarkand/hiking-amankutan.php',                  'default', 'private'],
            ['hiking-amankutan-shahrisabz',       'tours-from-samarkand/hiking-amankutan-shahrisabz.php',       'default', 'private'],
            ['tour-from-khiva-ancient-fortresses','tours-from-khiva/tour-from-khiva-ancient-fortresses.php',    'default', 'private'],
            ['daytrip-shahrisabz',                'tours-from-samarkand/daytrip-shahrisabz.php',                'default', 'private'],
            ['nuratau-homestay-3-days',           'tours-from-samarkand/nuratau-homestay-3-days.php',           'default', 'private'],
            ['seven-lakes-tajikistan-tour',       'tajikistan-tours/seven-lakes-tajikistan-tour.php',           'default', 'private'],
        ],
        3 => [
            ['daytrip-urgut-bazar-konigul-village', 'tours-from-samarkand/daytrip-urgut-bazar-konigul-village.php', 'default', 'private'],
            ['samarkand-city-tour',                 'tours-from-samarkand/samarkand-city-tour.php',                 'default', 'private'],
        ],
    ];

    public function handle(StaticSiteEditor $editor): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $wave   = $this->option('wave');
        $slug   = $this->option('slug');

        if (! $wave && ! $slug) {
            $this->error('Specify either --wave=N or --slug=...');

            return self::FAILURE;
        }

        $plan = $this->resolvePlan($wave, $slug);
        if ($plan === []) {
            $this->error('Nothing to do for that selection.');

            return self::FAILURE;
        }

        $root = rtrim((string) config('tour_export.site_root'), '/');
        $backupTag = (string) config('tour_export.rollout_backup_tag', 'bak-pricing-loader-' . date('Ymd'));

        $this->info(sprintf(
            '%s %d page(s)%s',
            $dryRun ? 'Dry run for' : 'Rolling out',
            count($plan),
            $wave ? " (wave {$wave})" : ''
        ));

        $ok = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($plan as [$slugKey, $rel, $direction, $type]) {
            $absolute = $root . '/' . ltrim($rel, '/');
            $this->line('');
            $this->line("→ {$slugKey}");
            $this->line("  file: {$absolute}");

            try {
                $result = $this->processPage($editor, $slugKey, $absolute, $direction, $type, $backupTag, $dryRun);
                $this->line('  ' . $result);
                $ok++;
            } catch (PageSkipped $e) {
                $this->warn('  SKIP: ' . $e->getMessage());
                $skipped++;
            } catch (Throwable $e) {
                $this->error('  FAIL: ' . $e->getMessage());
                $failed++;
            }
        }

        if (! $dryRun && $ok > 0) {
            if (app(StaticSitePageCache::class)->purge()) {
                $this->line('');
                $this->info('Purged nginx fastcgi cache.');
            } else {
                $this->warn('Could not purge nginx fastcgi cache — purge manually: rm -rf /var/cache/nginx/php/*');
            }
        }

        $this->line('');
        $this->info("Done. ok={$ok} skipped={$skipped} failed={$failed}");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<int, array{0:string, 1:string, 2:string, 3:string}>
     */
    private function resolvePlan(?string $wave, ?string $slug): array
    {
        if ($slug) {
            foreach (self::WAVES as $entries) {
                foreach ($entries as $row) {
                    if ($row[0] === $slug) {
                        return [$row];
                    }
                }
            }

            return [];
        }

        $n = (int) $wave;

        return self::WAVES[$n] ?? [];
    }

    private function processPage(
        StaticSiteEditor $editor,
        string $slug,
        string $absolute,
        string $direction,
        string $type,
        string $backupTag,
        bool $dryRun,
    ): string {
        if (! is_file($absolute)) {
            throw new PageSkipped('file does not exist');
        }

        $product = TourProduct::where('slug', $slug)->first();
        if (! $product) {
            throw new PageSkipped("no TourProduct row for slug={$slug}");
        }
        if ($product->priceTiers()->count() === 0) {
            throw new PageSkipped('TourProduct has zero price tiers');
        }
        // starting_from_usd is a decimal cast → string; normalize for the
        // editor's strict int|float signature.
        $startingFromRaw = (float) $product->starting_from_usd;
        $startingFrom = (int) $startingFromRaw == $startingFromRaw
            ? (int) $startingFromRaw
            : $startingFromRaw;

        $original = file_get_contents($absolute);
        if ($original === false) {
            throw new RuntimeException('read failed');
        }

        $errors = $editor->preflight($original);
        if ($errors !== []) {
            throw new PageSkipped(implode('; ', $errors));
        }

        $edited = $editor->injectLoaderBlock($original, $slug, $direction, $type);
        $edited = $editor->replacePriceCardTbody($edited);
        $edited = $editor->syncOffersPrice($edited, $startingFrom);

        $delta = strlen($edited) - strlen($original);

        if ($dryRun) {
            return "would convert: Δ={$delta} bytes, starting_from=\${$startingFrom}";
        }

        $backupPath = $absolute . '.' . $backupTag;
        if (file_exists($backupPath)) {
            throw new PageSkipped("backup already exists at {$backupPath}");
        }

        if (! copy($absolute, $backupPath)) {
            throw new RuntimeException("backup copy failed: {$backupPath}");
        }

        if (file_put_contents($absolute, $edited) === false) {
            throw new RuntimeException('write failed');
        }

        // Lint; on failure, restore from the backup we just made.
        exec('php -l ' . escapeshellarg($absolute) . ' 2>&1', $lintOut, $rc);
        if ($rc !== 0) {
            copy($backupPath, $absolute);
            throw new RuntimeException('lint failed: ' . implode(' ', $lintOut));
        }

        return "converted: Δ={$delta} bytes, starting_from=\${$startingFrom}, backup={$backupPath}";
    }
}

/**
 * Non-fatal skip — reported but doesn't count as failure.
 */
class PageSkipped extends RuntimeException
{
}
