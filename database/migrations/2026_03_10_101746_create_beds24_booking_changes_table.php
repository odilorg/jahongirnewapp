<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Audit trail — every change to a booking is recorded here.
     */
    public function up(): void
    {
        Schema::create('beds24_booking_changes', function (Blueprint $table) {
            $table->id();
            $table->string('beds24_booking_id')->comment('References beds24_bookings.beds24_booking_id');
            $table->string('change_type', 30)
                ->comment('created, modified, cancelled, payment_updated, status_changed, amount_changed');
            $table->json('old_data')->nullable()->comment('Previous field values');
            $table->json('new_data')->nullable()->comment('New field values');
            $table->timestamp('detected_at')->useCurrent()->comment('When the change was detected by the webhook');
            $table->timestamp('alerted_at')->nullable()->comment('When the owner was notified via Telegram');
            $table->timestamps();

            // Foreign key to bookings (soft-referenced by string for resilience)
            $table->index('beds24_booking_id');
            $table->index('change_type');
            $table->index('detected_at');
            $table->index('alerted_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('beds24_booking_changes');
    }
};
