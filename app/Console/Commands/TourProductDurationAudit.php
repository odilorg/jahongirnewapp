<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TourProduct;
use Illuminate\Console\Command;

/**
 * Reports tour_products whose `duration_days` looks inconsistent with their
 * slug/title (Phase 29 — read-only audit).
 *
 * Several multi-day tours are mis-tagged as 1 day (e.g.
 * `bukhara-yurt-camp-samarkand` = 1 for a 2-day overnight). The guest
 * experience engine deliberately does NOT trust this column (it uses the
 * per-slug catalog instead), but the data should still be corrected. This
 * command only REPORTS — it never mutates. An operator reviews and fixes.
 */
class TourProductDurationAudit extends Command
{
    protected $signature = 'tour-products:duration-audit';

    protected $description = 'Report tour products whose duration_days disagrees with their slug/title (read-only)';

    public function handle(): int
    {
        $suspects = [];

        foreach (TourProduct::query()->orderBy('id')->get() as $tp) {
            $inferred = $this->inferDays($tp->slug.' '.$tp->title);
            if ($inferred !== null && (int) $tp->duration_days !== $inferred) {
                $suspects[] = [
                    'id' => $tp->id,
                    'slug' => $tp->slug,
                    'stored' => (int) $tp->duration_days,
                    'inferred' => $inferred,
                ];
            }
        }

        if ($suspects === []) {
            $this->info('No duration_days mismatches detected.');

            return self::SUCCESS;
        }

        $this->warn(count($suspects).' tour(s) with suspicious duration_days (REVIEW — no changes made):');
        $this->table(
            ['id', 'slug', 'stored', 'inferred'],
            $suspects,
        );

        return self::SUCCESS;
    }

    /** Infer a day count from "Nd", "N-day", "N days", "N nights" hints. */
    private function inferDays(string $text): ?int
    {
        $t = strtolower($text);

        if (preg_match('/(\d+)\s*d\b/', $t, $m)) {       // "4d-3n"
            return (int) $m[1];
        }
        if (preg_match('/(\d+)\s*-?\s*day/', $t, $m)) {    // "2-day", "3 days"
            return (int) $m[1];
        }
        if (preg_match('/(\d+)\s*night/', $t, $m)) {       // "1 night" → 2 days
            return (int) $m[1] + 1;
        }

        return null;
    }
}
