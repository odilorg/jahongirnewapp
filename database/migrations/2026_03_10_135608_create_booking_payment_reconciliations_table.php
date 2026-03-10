<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_payment_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->string('beds24_booking_id');
            $table->string('property_id')->nullable();
            $table->decimal('expected_amount', 12, 2)->default(0);
            $table->decimal('reported_amount', 12, 2)->default(0);
            $table->decimal('discrepancy_amount', 12, 2)->default(0);
            $table->string('currency', 5)->default('UZS');
            $table->string('status')->default('pending'); // matched, underpaid, overpaid, no_payment, no_booking
            $table->timestamp('flagged_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();

            $table->index('beds24_booking_id');
            $table->index('status');
            $table->foreign('resolved_by')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_payment_reconciliations');
    }
};
