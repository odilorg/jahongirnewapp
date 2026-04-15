<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 — extend booking_inquiries with operational (tour dispatch) fields.
 *
 * Rationale: the legacy bookings/tours/guests/drivers/guides schema is dead
 * code (zero rows, circular FKs, form never successfully saved a row). Rather
 * than revive it, we treat booking_inquiries as the full lifecycle record for
 * direct website tour sales: intake → sales → payment → ops → completion.
 *
 * Driver and guide are stored as free-text strings on purpose. The normalised
 * drivers/guides tables exist but have no data, and starting with free-text
 * avoids committing to fake structure around unused tables. Can be normalised
 * later if supplier management matures.
 *
 * Two parallel status concepts:
 *   `status`       — commercial lifecycle (new → … → confirmed / cancelled)
 *   `prep_status`  — operational lifecycle (not_prepared → prepared → dispatched → completed)
 *
 * Deliberately separate fields so operators can filter by either axis.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('booking_inquiries', function (Blueprint $table) {
            $table->string('assigned_driver_name', 191)->nullable()->after('payment_method');
            $table->string('assigned_guide_name', 191)->nullable()->after('assigned_driver_name');
            $table->time('pickup_time')->nullable()->after('assigned_guide_name');
            $table->string('pickup_point', 255)->nullable()->after('pickup_time');
            $table->text('operational_notes')->nullable()->after('pickup_point');
            $table->string('prep_status', 32)->nullable()->after('operational_notes');
            $table->index('prep_status');
        });
    }

    public function down(): void
    {
        Schema::table('booking_inquiries', function (Blueprint $table) {
            $table->dropIndex(['prep_status']);
            $table->dropColumn([
                'assigned_driver_name',
                'assigned_guide_name',
                'pickup_time',
                'pickup_point',
                'operational_notes',
                'prep_status',
            ]);
        });
    }
};
