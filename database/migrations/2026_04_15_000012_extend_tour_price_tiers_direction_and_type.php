<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8.0b — Extend price tiers with direction + type.
 *
 * tour_product_direction_id is nullable: a tier with NULL direction
 * applies to ALL directions of the product ("global" tier). A tier
 * with a specific direction_id is route-specific and takes precedence
 * over the global tier for that direction.
 *
 * tour_type (private|group) affects pricing only — a group tour has
 * fixed per-person pricing regardless of group size, private has
 * sliding tiers.
 *
 * Default for existing rows: direction=NULL, type='private'. Matches
 * the shape of the Yurt tour that was seeded in 8.0.
 *
 * The old (tour_product_id, group_size) unique index is dropped
 * because we now scope uniqueness by (tour, direction, type, size)
 * and MySQL treats NULLs as distinct in unique indexes — making a
 * composite unique impractical for the global-tier case. Enforced in
 * application code via Filament form validation instead.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tour_price_tiers', function (Blueprint $table) {
            $table->foreignId('tour_product_direction_id')
                ->nullable()
                ->after('tour_product_id')
                ->constrained('tour_product_directions')
                ->nullOnDelete();

            $table->string('tour_type', 16)
                ->default('private')
                ->after('tour_product_direction_id');

            $table->dropUnique('tour_tier_unique_size');

            $table->index(['tour_product_id', 'tour_product_direction_id', 'tour_type'], 'tier_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::table('tour_price_tiers', function (Blueprint $table) {
            $table->dropIndex('tier_lookup_idx');
            $table->dropColumn('tour_type');
            $table->dropConstrainedForeignId('tour_product_direction_id');
            $table->unique(['tour_product_id', 'group_size'], 'tour_tier_unique_size');
        });
    }
};
