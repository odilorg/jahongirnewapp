<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8.0b — Tour product directions (route variants).
 *
 * One base product can have multiple route variants: Yurt Camp Tour
 * has sam-bukhara, sam-sam, bukhara-sam. Directions affect itinerary /
 * pickup / dropoff wording only. Type (private/group) affects pricing.
 * Keeping them separate prevents catalog duplication.
 *
 * code is unique within a tour_product_id, not globally — so two
 * different tours can each have 'sam-bukhara' directions.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tour_product_directions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tour_product_id')
                ->constrained('tour_products')
                ->cascadeOnDelete();

            $table->string('code', 64);          // 'sam-bukhara'
            $table->string('name', 191);         // 'Samarkand → Bukhara'
            $table->string('start_city', 64)->nullable();
            $table->string('end_city', 64)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique(['tour_product_id', 'code'], 'direction_code_unique_per_product');
            $table->index(['tour_product_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tour_product_directions');
    }
};
