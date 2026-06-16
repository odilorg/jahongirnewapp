<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TourProduct;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off, idempotent data fix: make the `yurt-camp-tour` price tiers
 * direction-agnostic by setting tour_product_direction_id = NULL.
 *
 * WHY: yurt tiers were authored under the route direction `sam-bukhara`
 * (id 1), but website/manual inquiries arrive tagged with the `default`
 * direction (which has no tiers), so TourProduct::priceFor() returns null
 * and the quote is unresolvable for ~17 live inquiries AND the human
 * Filament "Calculate quote". Yurt pricing is route-independent (operator
 * confirmed 2026-06-16), so null direction = "applies to any route".
 *
 * SCOPE GUARD: touches ONLY active price tiers of the single product with
 * slug `yurt-camp-tour`. Refuses if the slug resolves to 0 or >1 products.
 * No price values change — only the direction linkage. The TourPriceTier
 * observer recomputes starting_from_usd (no-op, cheapest tier unchanged).
 *
 * Idempotent: re-running after apply finds 0 rows to change.
 * Dry-run is the DEFAULT. Real writes require --apply.
 *
 *   php artisan tours:yurt-tiers-global            # dry-run (no writes)
 *   php artisan tours:yurt-tiers-global --apply    # writes (back up DB first)
 */
class MakeYurtTiersDirectionAgnostic extends Command
{
    protected $signature = 'tours:yurt-tiers-global {--apply : Persist the change (omit for dry-run; dry-run is the default)}';

    protected $description = 'Set yurt-camp-tour price tiers to NULL direction (route-independent pricing). Dry-run by default.';

    private const SLUG = 'yurt-camp-tour';

    private const SAMPLE_PARTY_SIZES = [1, 2, 3, 4];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $this->info('Mode: '.($apply ? 'APPLY (writes)' : 'DRY-RUN (no writes)'));

        $products = TourProduct::with(['priceTiers', 'directions'])
            ->where('slug', self::SLUG)->get();

        if ($products->count() !== 1) {
            $this->error("Expected exactly 1 product with slug '".self::SLUG."', found {$products->count()}. Aborting.");

            return self::FAILURE;
        }

        $product = $products->first();
        $targets = $product->priceTiers->whereNotNull('tour_product_direction_id');

        if ($targets->isEmpty()) {
            $this->info('✅ Nothing to do — all '.self::SLUG.' tiers are already direction-agnostic (idempotent no-op).');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('Tiers in scope (rollback mapping shown):');
        $this->table(
            ['tier_id', 'group', 'price/pp', 'type', 'current dir_id', 'new dir_id'],
            $targets->map(fn ($t): array => [
                $t->id, $t->group_size, '$'.$t->price_per_person_usd,
                $t->tour_type, $t->tour_product_direction_id, 'NULL',
            ])->all(),
        );

        $rollback = $targets
            ->map(fn ($t): string => "{$t->id}=>{$t->tour_product_direction_id}")
            ->implode(', ');
        $this->warn("Rollback mapping (tier_id => original dir_id): {$rollback}");

        // ── Before/after proof via priceFor (after = in-memory NULL clone) ──
        $this->newLine();
        $this->line('Quote resolution (private) before vs after:');
        $after = TourProduct::with(['priceTiers', 'directions'])->find($product->id);
        foreach ($after->priceTiers as $t) {
            $t->tour_product_direction_id = null; // in-memory only, never saved here
        }
        $rows = [];
        foreach (self::SAMPLE_PARTY_SIZES as $pax) {
            $b = $product->priceFor($pax, 'default', 'private');
            $a = $after->priceFor($pax, 'default', 'private');
            $rows[] = [
                "party {$pax}",
                $b ? '$'.$b->price_per_person_usd.'/pp' : 'MANUAL (unresolvable)',
                $a ? '$'.$a->price_per_person_usd.'/pp = $'.number_format($a->totalForGroup(), 2).' total' : 'still MANUAL',
            ];
        }
        $this->table(['default direction', 'BEFORE', 'AFTER'], $rows);

        if (! $apply) {
            $this->newLine();
            $this->warn('DRY-RUN: no changes made. Re-run with --apply (after a DB backup) to persist.');

            return self::SUCCESS;
        }

        // ── Apply (atomic) ─────────────────────────────────────────────
        DB::transaction(function () use ($targets): void {
            foreach ($targets as $tier) {
                $tier->forceFill(['tour_product_direction_id' => null])->save();
            }
        });

        $this->newLine();
        $this->info('✅ Applied: '.$targets->count().' yurt tier(s) set to NULL direction.');
        $this->line("Rollback: php artisan tinker --execute=\"foreach(['{$rollback}'] ...)\"  OR set each tier_id back to its original dir_id above.");

        return self::SUCCESS;
    }
}
