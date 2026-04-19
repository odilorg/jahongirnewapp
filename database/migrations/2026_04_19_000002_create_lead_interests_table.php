<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lead_interests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();

            // Nullable FK: the catalog may not yet contain a matching product.
            $table->foreignId('tour_product_id')
                ->nullable()
                ->constrained('tour_products')
                ->nullOnDelete();
            $table->string('tour_freeform')->nullable();

            $table->date('requested_date')->nullable();
            $table->string('requested_date_flex')->nullable();

            $table->unsignedSmallInteger('pax_adults')->nullable();
            $table->unsignedSmallInteger('pax_children')->nullable();

            $table->string('format', 16)->default('unknown');
            $table->string('direction_code', 32)->nullable();
            $table->string('pickup_city')->nullable();
            $table->string('dropoff_city')->nullable();

            $table->text('dietary_requirements')->nullable();
            $table->text('special_requests')->nullable();
            $table->text('notes')->nullable();

            $table->string('status', 32)->default('exploring');

            $table->timestamps();

            $table->index(['lead_id', 'status']);
            $table->index('tour_product_id');
            $table->index('direction_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_interests');
    }
};
