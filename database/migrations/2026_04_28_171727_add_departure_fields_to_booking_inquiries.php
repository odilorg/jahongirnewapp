<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 — Wire booking_inquiries to departures.
 *
 * Adds the demand-side link to the supply-side departure entity. All four
 * columns are nullable; existing inquiries (OTA, manual, GYG, website-direct)
 * continue to work unchanged with NULL values.
 *
 * Per G7 (no backfill): this migration adds the columns but does NOT update
 * any existing row. Operator-driven manual linking via Filament edit form is
 * the only sanctioned path to set departure_id on a historical inquiry.
 *
 * payment_due_at and seat_hold_expires_at are intentionally separate
 * timestamps. Per Q4 + PHASE_0 §5.0 Seat Mutation Matrix:
 *   - seat_hold_expires_at: when the seat reservation is released (cron)
 *   - payment_due_at: when the payment-reminder cron escalates
 * They start at the same value but may diverge as reminder cadence evolves.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('booking_inquiries', function (Blueprint $table) {
            $table->foreignId('departure_id')
                ->nullable()
                ->after('tour_product_direction_id')
                ->constrained('departures')
                ->nullOnDelete();

            $table->unsignedSmallInteger('seats_held')
                ->nullable()
                ->after('departure_id');

            $table->timestamp('seat_hold_expires_at')
                ->nullable()
                ->after('seats_held');

            $table->timestamp('payment_due_at')
                ->nullable()
                ->after('seat_hold_expires_at');

            $table->index('seat_hold_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('booking_inquiries', function (Blueprint $table) {
            $table->dropForeign(['departure_id']);

            // MySQL/MariaDB may auto-drop the FK-backed index; Postgres keeps it.
            // hasIndex() guards portability.
            if (Schema::hasIndex('booking_inquiries', 'booking_inquiries_departure_id_index')) {
                $table->dropIndex(['departure_id']);
            }
            if (Schema::hasIndex('booking_inquiries', 'booking_inquiries_seat_hold_expires_at_index')) {
                $table->dropIndex(['seat_hold_expires_at']);
            }

            $table->dropColumn([
                'departure_id',
                'seats_held',
                'seat_hold_expires_at',
                'payment_due_at',
            ]);
        });
    }
};
