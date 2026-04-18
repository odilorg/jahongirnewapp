<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 14.1 — Supplier payment ledger.
 *
 * Tracks actual payments to drivers, guides, and accommodations.
 * Booking-level cost fields remain the source of truth for "owed";
 * this table records "paid".
 */
return new class extends Migration
{
    public function up(): void
    {
        // L-001 (2026-04-18): supersedes the 2024_08_22_130240 supplier_payments
        // schema. On fresh installs the v1 migration runs first; without this
        // drop v2 would throw "table already exists".
        // Safe in production — Laravel does not re-execute this migration.
        // See docs/architecture/MIGRATION_HISTORY.md.
        Schema::dropIfExists('supplier_payments');

        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->string('supplier_type', 20)->index()
                ->comment('driver | guide | accommodation');
            $table->unsignedBigInteger('supplier_id')->index();
            $table->foreignId('booking_inquiry_id')->nullable()
                ->constrained('booking_inquiries')->nullOnDelete();
            $table->decimal('amount', 10, 2);
            $table->char('currency', 3)->default('USD');
            $table->date('payment_date');
            $table->string('payment_method', 20)->default('cash')
                ->comment('cash | bank_transfer | card | other');
            $table->string('reference', 100)->nullable()
                ->comment('Transfer ref, receipt number');
            $table->text('notes')->nullable();
            $table->string('receipt_path', 500)->nullable();
            $table->foreignId('paid_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('recorded')
                ->comment('recorded | voided');
            $table->timestamps();

            $table->index(['supplier_type', 'supplier_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
    }
};
