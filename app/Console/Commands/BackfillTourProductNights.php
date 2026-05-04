<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TourProduct;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Set tour_products.duration_nights = duration_days - 1 for any catalog
 * row where days > 1 and nights still 0 (the column was never populated
 * during catalog import — every row defaulted to 0).
 *
 * Why this matters: the calendar uses duration_days/duration_nights to
 * place chips and label them "2D/1N". Until this backfill runs, every
 * multi-day tour reads as "2D/0N" — confusing for dispatchers and
 * incorrect on guest-facing surfaces.
 *
 * Conservative rule: nights = days - 1. Holds for the vast majority of
 * itineraries (yurt camps, homestays, multi-city loops). Tours that
 * deviate (e.g. early-departure same-day-return where days=2 nights=2)
 * must be corrected manually after this run; this command never
 * over-writes a non-zero existing value.
 *
 * Usage:
 *   php artisan tour:backfill-product-nights --dry-run   # propose only
 *   php artisan tour:backfill-product-nights             # apply
 *
 * Idempotent: re-running after apply prints "no rows to update".
 */
class BackfillTourProductNights extends Command
{
    protected $signature   = 'tour:backfill-product-nights {--dry-run : Print proposed changes without applying}';
    protected $description = 'Set tour_products.duration_nights = duration_days - 1 where missing';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $rows = TourProduct::query()
            ->where('duration_days', '>', 1)
            ->where('duration_nights', 0)
            ->orderBy('id')
            ->get(['id', 'title', 'duration_days', 'duration_nights']);

        if ($rows->isEmpty()) {
            $this->info('No rows to update — every multi-day tour already has nights set.');
            return self::SUCCESS;
        }

        $this->info(($dryRun ? '[DRY-RUN] ' : '') . "Will update " . $rows->count() . " row(s):");
        $this->newLine();

        foreach ($rows as $row) {
            $proposed = (int) $row->duration_days - 1;
            $this->line(sprintf(
                '  #%d  %dD/%dN → %dD/%dN  ·  %s',
                $row->id,
                $row->duration_days,
                $row->duration_nights,
                $row->duration_days,
                $proposed,
                mb_substr((string) $row->title, 0, 70),
            ));
        }

        if ($dryRun) {
            $this->newLine();
            $this->info('Dry-run complete. Re-run without --dry-run to apply.');
            return self::SUCCESS;
        }

        $this->newLine();
        $applied = 0;
        foreach ($rows as $row) {
            $proposed = (int) $row->duration_days - 1;
            $row->forceFill(['duration_nights' => $proposed])->save();
            $applied++;
        }

        Log::info('BackfillTourProductNights: applied', [
            'rows_updated' => $applied,
        ]);

        $this->info("✅ Updated {$applied} tour_product row(s).");
        $this->newLine();
        $this->warn('Manual review still needed for tours where duration_days itself is wrong:');
        $this->warn('  · "Bukhara - Nuratau - Yurt Camp" (id=6)  — title implies overnight but days=1');
        $this->warn('  · "Bukhara Yurt Camp tour"        (id=7)  — same');
        $this->warn('  · "Nuratau Homestay extended …"   (id=15) — title says "extended"');
        $this->warn('  · "Cycling Tour Silk Road"        (id=18) — multi-day route');
        $this->warn('  · "7 Mysterious Nights"           (id=20) — 7 nights per title');
        $this->warn('Edit those manually in the Tour Products admin.');

        return self::SUCCESS;
    }
}
