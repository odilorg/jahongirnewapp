<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8.0 — Tour catalog (sales product layer).
 *
 * NEW table, deliberately not reusing the dead legacy `tours` table
 * which has zero rows and a circular `booking_id NOT NULL` FK from
 * Phase 1 investigation. Clean break.
 *
 * TourProduct represents WHAT WE SELL — public-facing tour packages
 * with display copy and selling-price tiers. It does NOT model internal
 * supplier costing (driver day rates, accommodation rate cards, etc.) —
 * that lives in separate supplier-rate tables and stays separate
 * deliberately. Sales catalog vs internal costing.
 *
 * source_type / source_path track where each row came from so the
 * Phase 8.1 importer can distinguish admin-created tours from
 * static-site-imported tours and avoid blowing away operator edits
 * on re-import.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tour_products', function (Blueprint $table) {
            $table->id();

            $table->string('slug', 191)->unique();
            $table->string('title', 255);

            // Fixed enum-ish via string + index — cleaner than DB enum
            // (mysql enum migrations are painful) but still filterable.
            $table->string('region', 32)->index(); // samarkand|bukhara|khiva|tajikistan|uzbekistan|nuratau
            $table->string('tour_type', 16)->default('private')->index(); // private|group

            $table->unsignedSmallInteger('duration_days')->default(1);
            $table->unsignedSmallInteger('duration_nights')->default(0);

            // Cached for list display + filters; recomputed when tiers change.
            $table->decimal('starting_from_usd', 10, 2)->nullable();
            $table->char('currency', 3)->default('USD');

            $table->text('description')->nullable();
            // Bullet points as a JSON array of strings — better for
            // rendering on the website than a markdown blob.
            $table->json('highlights')->nullable();
            $table->text('includes')->nullable();
            $table->text('excludes')->nullable();

            $table->string('hero_image_url', 500)->nullable();
            $table->string('page_url', 500)->nullable();
            $table->string('meta_description', 500)->nullable();

            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('sort_order')->default(0);

            // Provenance — useful for the Phase 8.1 importer to detect
            // drift and avoid overwriting operator edits.
            $table->string('source_type', 32)->default('manual'); // manual|website_static|api
            $table->string('source_path', 500)->nullable();
            $table->string('import_hash', 64)->nullable();
            $table->timestamp('last_imported_at')->nullable();

            $table->timestamps();

            $table->index(['region', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tour_products');
    }
};
