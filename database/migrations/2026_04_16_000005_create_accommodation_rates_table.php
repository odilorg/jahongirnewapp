<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12.1 — Unified accommodation pricing.
 *
 * Supports both per-person (yurt camps, homestays) and per-room
 * (hotels, B&Bs) pricing from one table. The rate_type field drives
 * the calculation path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accommodation_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accommodation_id')->constrained()->cascadeOnDelete();
            $table->enum('rate_type', ['per_person', 'per_room']);
            $table->string('room_type', 50)->nullable()->comment('single, double, triple, yurt, dorm, etc.');
            $table->string('label', 100)->comment('Human-readable: "1 person yurt", "Double room"');
            $table->unsignedSmallInteger('min_occupancy')->default(1);
            $table->unsignedSmallInteger('max_occupancy')->nullable()->comment('NULL = no upper limit');
            $table->decimal('cost_usd', 10, 2);
            $table->text('includes')->nullable()->comment('dinner + breakfast, etc.');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['accommodation_id', 'rate_type', 'is_active']);
        });

        // Add cost tracking fields to inquiry_stays
        Schema::table('inquiry_stays', function (Blueprint $table) {
            $table->foreignId('accommodation_rate_id')->nullable()->after('accommodation_id')
                ->constrained('accommodation_rates')->nullOnDelete();
            $table->string('room_type', 50)->nullable()->after('accommodation_rate_id');
            $table->unsignedSmallInteger('room_count')->default(1)->after('room_type');
            $table->decimal('cost_per_unit_usd', 10, 2)->nullable()->after('room_count')
                ->comment('Resolved from rate: per person or per room');
            $table->decimal('total_accommodation_cost', 10, 2)->nullable()->after('cost_per_unit_usd');
            $table->boolean('cost_override')->default(false)->after('total_accommodation_cost')
                ->comment('True if operator manually changed the auto-calculated cost');
        });
    }

    public function down(): void
    {
        Schema::table('inquiry_stays', function (Blueprint $table) {
            $table->dropForeign(['accommodation_rate_id']);
            $table->dropColumn([
                'accommodation_rate_id', 'room_type', 'room_count',
                'cost_per_unit_usd', 'total_accommodation_cost', 'cost_override',
            ]);
        });

        Schema::dropIfExists('accommodation_rates');
    }
};
