<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\BookingInquiries\EnrichWebsiteInquiryFromTourSlugAction;
use App\Models\BookingInquiry;
use Illuminate\Console\Command;

/**
 * One-shot backfill of website inquiries that pre-date the
 * EnrichWebsiteInquiryFromTourSlugAction wiring (introduced for the
 * 2026-05-10 incident — booking 109, Andrea Sterrantino).
 *
 * Scope:
 *   - source = 'website'
 *   - tour_slug IS NOT NULL
 *   - tour_product_id IS NULL
 *   - exact slug match against tour_products.slug exists
 *
 * Does NOT backfill:
 *   - pickup_time (operator data, not derivable safely from history)
 *   - travel_date (already correct as date-only)
 *   - rows from other sources (gyg, viator, whatsapp, manual)
 *
 * Idempotent: re-running has no effect once tour_product_id is set.
 */
class BackfillWebsiteInquiryTourProducts extends Command
{
    protected $signature = 'tour:backfill-website-inquiry-tour-products
        {--dry-run : Print candidates without writing}';

    protected $description = 'Backfill tour_product_id/direction_id/tour_type on legacy website inquiries with matching tour_slug';

    public function handle(EnrichWebsiteInquiryFromTourSlugAction $enrich): int
    {
        $candidates = BookingInquiry::query()
            ->where('source', 'website')
            ->whereNotNull('tour_slug')
            ->whereNull('tour_product_id')
            ->orderBy('id')
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('No candidates found — every website inquiry with a slug is already linked.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s%d candidate(s) found:',
            $this->option('dry-run') ? '[DRY-RUN] ' : '',
            $candidates->count(),
        ));

        $linked = 0;
        $unmatched = 0;

        foreach ($candidates as $inquiry) {
            $beforeFkNull = $inquiry->tour_product_id === null;

            if ($this->option('dry-run')) {
                $product = \App\Models\TourProduct::where('slug', $inquiry->tour_slug)->first();
                $verdict = $product ? "→ would link to product #{$product->id} ({$product->title})" : '→ slug NOT in catalog (skip)';
                $this->line(sprintf(
                    '  #%d | %s | %s | %s',
                    $inquiry->id,
                    str_pad($inquiry->tour_slug, 35),
                    str_pad($inquiry->customer_name, 28),
                    $verdict,
                ));
                $product ? $linked++ : $unmatched++;
                continue;
            }

            $enrich->handle($inquiry);

            $inquiry->refresh();
            if ($beforeFkNull && $inquiry->tour_product_id !== null) {
                $linked++;
                $this->line(sprintf('  ✅ #%d → product #%d', $inquiry->id, $inquiry->tour_product_id));
            } else {
                $unmatched++;
                $this->line(sprintf('  ⏭  #%d (slug "%s" not in catalog)', $inquiry->id, $inquiry->tour_slug));
            }
        }

        $this->info(sprintf(
            '%sDone. Linked: %d. Unmatched: %d.',
            $this->option('dry-run') ? '[DRY-RUN] ' : '',
            $linked,
            $unmatched,
        ));

        return self::SUCCESS;
    }
}
