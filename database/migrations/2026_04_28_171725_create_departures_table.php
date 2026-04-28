<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 — Yurt Camp Departure Engine.
 *
 * Implements PHASE_1_DEPARTURE_CORE_SPEC.md §1.1 and PHASE_0_ARCHITECTURE_LOCK.md.
 *
 * Departures are the supply-side entity: scheduled, capacity-bounded units that
 * group bookings together. Both group (publicly listed, multi-booking) and
 * private (1:1 with a booking, never listed) departures share this table; the
 * tour_type discriminator + DeparturePolicy resolver decides behavior.
 *
 * Booking inquiries link to a departure via booking_inquiries.departure_id
 * (added in 2026_04_28_171727).
 *
 * Lifecycle (forward-only past `guaranteed`):
 *   draft → open → guaranteed → confirmed → departed → completed
 *                       ↘ cancelled / cancelled_min_pax
 *
 * Indexes target three query paths:
 *   - admin lists by status + date
 *   - public listings by tour_type + status
 *   - cron evaluations by cutoff_at / guarantee_at
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('departures', function (Blueprint $table) {
            $table->id();

            // Identity
            $table->string('reference', 32)->unique();

            // Catalog linkage. restrictOnDelete: a tour_product cannot be deleted
            // while departures reference it — operators must cancel first.
            $table->foreignId('tour_product_id')
                ->constrained('tour_products')
                ->restrictOnDelete();
            $table->foreignId('tour_product_direction_id')
                ->nullable()
                ->constrained('tour_product_directions')
                ->nullOnDelete();
            $table->string('tour_type', 16);

            // Schedule
            $table->date('departure_date');
            $table->time('pickup_time')->nullable();
            $table->string('pickup_point', 255)->nullable();
            $table->string('dropoff_point', 255)->nullable();

            // Capacity & thresholds
            $table->unsignedSmallInteger('capacity_seats');
            $table->unsignedSmallInteger('minimum_pax');
            $table->timestamp('cutoff_at')->nullable();
            $table->timestamp('guarantee_at')->nullable();

            // Lifecycle
            $table->string('status', 32)->default('draft');

            // Pricing snapshot — frozen on first transition out of draft (G6).
            $table->decimal('price_per_person_usd_snapshot', 10, 2);
            $table->decimal('single_supplement_usd_snapshot', 10, 2)->nullable();
            $table->char('currency', 3)->default('USD');

            // Suppliers (nullable until assigned). nullOnDelete preserves history.
            $table->foreignId('driver_id')->nullable()
                ->constrained('drivers')->nullOnDelete();
            $table->foreignId('guide_id')->nullable()
                ->constrained('guides')->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()
                ->constrained('cars')->nullOnDelete();

            // Operational
            $table->text('operational_notes')->nullable();
            $table->string('cancelled_reason', 255)->nullable();

            // Lifecycle audit timestamps (set by actions, never auto).
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('guaranteed_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('departed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            // Provenance
            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // Query-path indexes
            $table->index(['tour_product_id', 'departure_date']);
            $table->index(['status', 'departure_date']);
            $table->index(['tour_type', 'status']);
            $table->index('cutoff_at');
            $table->index('guarantee_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departures');
    }
};
