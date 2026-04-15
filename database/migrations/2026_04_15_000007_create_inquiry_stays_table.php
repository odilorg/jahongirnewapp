<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5.3 — Many-to-many junction with extra fields between booking
 * inquiries and accommodations. Models a single "stay" along the tour.
 *
 * One inquiry → many stays:
 *   Nuratau 3-day = night 1 yurt camp + night 2 village homestay
 *
 * Each row carries its own date / nights / guest count / meal plan so
 * dispatch messages and cost calculations can target a specific leg of
 * the tour rather than the whole booking.
 *
 * sort_order lets operators control how the stays appear on the inquiry
 * detail page (chronological by tour day, not insertion order).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('inquiry_stays', function (Blueprint $table) {
            $table->id();

            $table->foreignId('booking_inquiry_id')
                ->constrained('booking_inquiries')
                ->cascadeOnDelete();

            $table->foreignId('accommodation_id')
                ->constrained('accommodations')
                ->cascadeOnDelete();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->date('stay_date')->nullable();
            $table->unsignedSmallInteger('nights')->default(1);
            $table->unsignedSmallInteger('guest_count')->nullable();
            $table->string('meal_plan', 100)->nullable(); // "B+D", "HB", "B+L+D", free text
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['booking_inquiry_id', 'sort_order']);
            $table->index('stay_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiry_stays');
    }
};
