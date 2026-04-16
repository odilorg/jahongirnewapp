<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12.3 — Driver rate cards + booking-level cost tracking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();
            $table->string('label', 100);
            $table->enum('rate_type', ['per_trip', 'per_day']);
            $table->decimal('cost_usd', 10, 2);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['driver_id', 'is_active']);
        });

        Schema::table('booking_inquiries', function (Blueprint $table) {
            $table->foreignId('driver_rate_id')->nullable()->after('guide_id')
                ->constrained('driver_rates')->nullOnDelete();
            $table->decimal('driver_cost', 10, 2)->nullable()->after('driver_rate_id');
            $table->boolean('driver_cost_override')->default(false)->after('driver_cost');
            $table->string('driver_cost_override_reason', 255)->nullable()->after('driver_cost_override');
            $table->decimal('guide_cost', 10, 2)->nullable()->after('driver_cost_override_reason');
            $table->decimal('other_costs', 10, 2)->nullable()->after('guide_cost');
            $table->text('cost_notes')->nullable()->after('other_costs');
        });
    }

    public function down(): void
    {
        Schema::table('booking_inquiries', function (Blueprint $table) {
            $table->dropForeign(['driver_rate_id']);
            $table->dropColumn([
                'driver_rate_id', 'driver_cost', 'driver_cost_override',
                'driver_cost_override_reason', 'guide_cost', 'other_costs', 'cost_notes',
            ]);
        });

        Schema::dropIfExists('driver_rates');
    }
};
