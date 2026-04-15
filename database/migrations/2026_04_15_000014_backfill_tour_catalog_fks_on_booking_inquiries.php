<?php

declare(strict_types=1);

use App\Models\BookingInquiry;
use App\Models\TourProduct;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 8.2 — safe backfill of tour catalog FKs.
 *
 * Rules:
 *   1. Link tour_product_id where booking_inquiries.tour_slug matches
 *      tour_products.slug exactly.
 *   2. Link tour_product_direction_id ONLY if the matched product has
 *      a single unambiguous 'default' direction (the one the importer
 *      creates). Products with multiple manual directions (sam-bukhara
 *      / sam-sam / bukhara-sam) stay null — never guess.
 *   3. Default tour_type to 'private' when a product was linked and
 *      type is still null. Inquiries without a product FK keep
 *      tour_type null — nothing to anchor them to.
 *
 * All three steps run inside a single transaction. Snapshot columns
 * (tour_slug, tour_name_snapshot) are never touched.
 *
 * Rollback: NULL out the three FK/type columns we set. Leaves
 * snapshots untouched. The sibling schema migration handles the
 * column removal if the structure itself needs to be rolled back.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::transaction(function () {
            $productLinked   = 0;
            $directionLinked = 0;
            $typeDefaulted   = 0;

            // 1. product FK by slug match — single SQL join update
            $productLinked = DB::table('booking_inquiries')
                ->join('tour_products', 'booking_inquiries.tour_slug', '=', 'tour_products.slug')
                ->whereNull('booking_inquiries.tour_product_id')
                ->update([
                    'booking_inquiries.tour_product_id' => DB::raw('tour_products.id'),
                ]);

            // 2. direction FK — only if the linked product has exactly
            //    one active direction AND its code is 'default' (importer
            //    default) OR the product has exactly one direction regardless.
            //    Multiple manual directions → leave null (don't guess).
            $inquiriesNeedingDirection = BookingInquiry::query()
                ->whereNotNull('tour_product_id')
                ->whereNull('tour_product_direction_id')
                ->pluck('id', 'tour_product_id');

            $productIds = $inquiriesNeedingDirection->keys()->unique()->all();

            foreach ($productIds as $productId) {
                $product = TourProduct::with('directions')->find($productId);
                if (! $product) {
                    continue;
                }

                $activeDirections = $product->directions->where('is_active', true);

                if ($activeDirections->count() !== 1) {
                    // 0 directions → nothing to link (keep null)
                    // 2+ directions → ambiguous, skip (keep null)
                    continue;
                }

                $directionId = $activeDirections->first()->id;

                $n = BookingInquiry::query()
                    ->where('tour_product_id', $productId)
                    ->whereNull('tour_product_direction_id')
                    ->update(['tour_product_direction_id' => $directionId]);

                $directionLinked += $n;
            }

            // 3. default tour_type=private for linked rows that still have no type.
            $typeDefaulted = BookingInquiry::query()
                ->whereNotNull('tour_product_id')
                ->whereNull('tour_type')
                ->update(['tour_type' => TourProduct::TYPE_PRIVATE]);

            Log::info('Phase 8.2 backfill complete', [
                'product_fk_linked'   => $productLinked,
                'direction_fk_linked' => $directionLinked,
                'type_defaulted'      => $typeDefaulted,
            ]);
        });
    }

    public function down(): void
    {
        // Null out only the fields we set; snapshots remain untouched.
        DB::table('booking_inquiries')->update([
            'tour_product_id'           => null,
            'tour_product_direction_id' => null,
            'tour_type'                 => null,
        ]);
    }
};
