<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8.0 — Selling price tiers per tour product.
 *
 * One row per group_size value. Most existing static-site pricing
 * tables use exact group sizes (1, 2, 3, 4, 5, 6) so we model that
 * shape directly. Range-based tiers (e.g. "3–4 persons") can be
 * added later by introducing min_group_size / max_group_size columns
 * if the data demands it.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tour_price_tiers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tour_product_id')
                ->constrained('tour_products')
                ->cascadeOnDelete();

            $table->unsignedSmallInteger('group_size'); // 1..N (exact match)
            $table->decimal('price_per_person_usd', 10, 2);
            $table->string('notes', 255)->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['tour_product_id', 'group_size']);
            $table->unique(['tour_product_id', 'group_size'], 'tour_tier_unique_size');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tour_price_tiers');
    }
};
