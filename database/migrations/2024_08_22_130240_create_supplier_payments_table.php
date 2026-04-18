<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DEPRECATED (superseded by 2026_04_16_000010_create_supplier_payments_table).
 *
 * Original schema (tour_booking_id + driver_id + guide_id + amount_paid)
 * replaced in April 2026 by a polymorphic (supplier_type, supplier_id)
 * design linked to booking_inquiry_id. File kept because the migration
 * row is referenced in the production `migrations` table.
 *
 * On fresh installs this migration runs first; the v2 migration
 * (2026_04_16_000010) calls Schema::dropIfExists before recreating.
 * Do NOT delete this file.
 *
 * See docs/architecture/MIGRATION_HISTORY.md.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->foreignId('tour_booking_id');
            $table->foreignId('driver_id');
            $table->foreignId('guide_id');
            $table->double('amount_paid'); 
            $table->date('payment_date');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
    }
};
