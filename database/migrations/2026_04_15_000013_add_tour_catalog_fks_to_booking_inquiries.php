<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8.2 — link booking_inquiries to the tour catalog.
 *
 * Adds three NULLABLE foreign keys. Rule locked in memory:
 *   FK fields are for STRUCTURE
 *   snapshot fields (tour_slug, tour_name_snapshot) are HISTORICAL TRUTH
 *
 * We never remove or overwrite snapshot columns. They stay exactly where
 * they are. This migration is additive, safe, and non-destructive.
 *
 * The data backfill lives in a separate migration
 * (2026_04_15_000014_backfill_tour_catalog_fks_on_booking_inquiries.php)
 * so that rolling back the backfill does not drop the schema change,
 * and the schema change can be rolled back cleanly if needed.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('booking_inquiries', function (Blueprint $table) {
            $table->foreignId('tour_product_id')
                ->nullable()
                ->after('tour_name_snapshot')
                ->constrained('tour_products')
                ->nullOnDelete();

            $table->foreignId('tour_product_direction_id')
                ->nullable()
                ->after('tour_product_id')
                ->constrained('tour_product_directions')
                ->nullOnDelete();

            // private | group — matches TourProduct::TYPES
            $table->string('tour_type', 16)
                ->nullable()
                ->after('tour_product_direction_id');

            $table->index('tour_type');
        });
    }

    public function down(): void
    {
        Schema::table('booking_inquiries', function (Blueprint $table) {
            $table->dropIndex(['tour_type']);
            $table->dropColumn('tour_type');
            $table->dropConstrainedForeignId('tour_product_direction_id');
            $table->dropConstrainedForeignId('tour_product_id');
        });
    }
};
