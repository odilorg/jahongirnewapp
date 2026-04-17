<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 16 — Guest payment ledger.
 *
 * Mirrors supplier_payments but for money RECEIVED from guests.
 * Refunds = negative amount rows (not a separate status).
 * Always linked to a booking_inquiry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_inquiry_id')
                ->constrained('booking_inquiries')->cascadeOnDelete();
            $table->decimal('amount', 10, 2)
                ->comment('Positive = received, negative = refund');
            $table->char('currency', 3)->default('USD');
            $table->string('payment_type', 20)->default('full')
                ->comment('deposit | balance | full | extra');
            $table->string('payment_method', 20)
                ->comment('octo | cash | card_office | bank_transfer | paypal | gyg | other');
            $table->date('payment_date');
            $table->string('reference', 100)->nullable()
                ->comment('Octo txn ID, receipt #, bank ref');
            $table->text('notes')->nullable();
            $table->string('receipt_path', 500)->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('recorded')
                ->comment('recorded | voided');
            $table->timestamps();

            $table->index(['booking_inquiry_id', 'status']);
            $table->index('payment_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_payments');
    }
};
