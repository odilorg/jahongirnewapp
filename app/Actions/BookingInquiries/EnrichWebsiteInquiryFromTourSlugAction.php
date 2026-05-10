<?php

declare(strict_types=1);

namespace App\Actions\BookingInquiries;

use App\Models\BookingInquiry;
use App\Models\TourProduct;

/**
 * Link a website inquiry to its tour catalog entry.
 *
 * Website intake (POST /api/v1/inquiries) receives `tour_slug` from the
 * jahongir-travel.uz form. Without this Action the slug stays as a snapshot
 * string and tour_product_id remains NULL — the calendar cannot infer
 * duration_days, so multi-day tours render as 1-day chips (incident:
 * BookingInquiry id 109, Andrea Sterrantino, 2026-05-10 — 2-Day Yurt Camp
 * showed only on day 1 because tour_product_id was NULL).
 *
 * Idempotent: only writes fields that are currently NULL on the inquiry.
 * Operators can override these via Filament without being clobbered if the
 * Action runs again. Unknown slugs are a graceful no-op (operator fixes
 * manually) — never invents a wrong tour_product_id.
 */
class EnrichWebsiteInquiryFromTourSlugAction
{
    public function handle(BookingInquiry $inquiry): void
    {
        if (! $inquiry->tour_slug) {
            return;
        }

        $product = TourProduct::where('slug', $inquiry->tour_slug)->first();
        if (! $product) {
            // Unknown slug — never guess. Operator must fix manually if needed.
            return;
        }

        // No `is_default` column on tour_product_directions today, so we
        // pick the first direction by sort_order then id. Operators can
        // change to the correct direction in Filament; the calendar uses
        // tourProduct.duration_days (not direction-specific) for chip span,
        // so an imperfect default direction does not break the visual.
        $direction = $product->directions()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        $update = [];
        if ($inquiry->tour_product_id === null) {
            $update['tour_product_id'] = $product->id;
        }
        if ($inquiry->tour_product_direction_id === null && $direction !== null) {
            $update['tour_product_direction_id'] = $direction->id;
        }
        if ($inquiry->tour_type === null) {
            // Catalog default; safe fallback to PRIVATE only when the column
            // is genuinely unset. Use null-coalesce (not `?:`) so an empty
            // string or '0' isn't silently coerced to PRIVATE — that would
            // be a hidden default, forbidden by the AI-coding safety policy.
            $update['tour_type'] = $product->tour_type ?? TourProduct::TYPE_PRIVATE;
        }

        if (! empty($update)) {
            $inquiry->forceFill($update)->save();
        }
    }
}
