<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DEPRECATED (superseded by 2026_04_17_000002_create_guest_payments_table).
 *
 * Original schema (guest_id + booking_id + payment_status) replaced
 * in April 2026 by a booking_inquiry_id-based design with refund
 * semantics (positive/negative amounts). File kept because the row
 * `2025_03_04_033652_create_guest_payments_table` is referenced in
 * the production `migrations` table (id=120, batch=4).
 *
 * On fresh installs this migration runs first; the v2 migration
 * (2026_04_17_000002) calls Schema::dropIfExists before recreating.
 * Do NOT delete this file.
 *
 * See docs/architecture/MIGRATION_HISTORY.md and
 * docs/architecture/L-001_execution_plan.md.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('guest_payments', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->foreignId('guest_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->date('payment_date');
            $table->string('payment_method');
            $table->string('payment_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guest_payments');
    }
};
