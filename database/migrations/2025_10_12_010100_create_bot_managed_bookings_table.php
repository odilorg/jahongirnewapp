<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_managed_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_request_id')->constrained('staff_booking_requests')->onDelete('cascade');
            $table->string('beds24_booking_id', 100)->unique();
            $table->string('property_id', 100);
            $table->string('property_name');
            $table->string('room_id', 100);
            $table->string('room_name')->nullable();
            $table->string('unit_name', 50)->nullable();
            
            // Guest info
            $table->string('guest_name');
            $table->string('guest_email')->nullable();
            $table->string('guest_phone', 50)->nullable();
            
            // Dates
            $table->date('check_in_date');
            $table->date('check_out_date');
            
            // Pricing
            $table->decimal('total_price', 10, 2)->nullable();
            $table->string('currency', 3)->default('USD');
            
            // Status
            $table->enum('booking_status', [
                'confirmed',
                'checked_in',
                'checked_out',
                'cancelled',
                'no_show'
            ])->default('confirmed');
            
            // Metadata
            $table->foreignId('created_by_staff_id')->constrained('authorized_staff');
            $table->foreignId('cancelled_by_staff_id')->nullable()->constrained('authorized_staff');
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            
            $table->timestamps();
            
            $table->index('beds24_booking_id');
            $table->index('booking_status');
            $table->index(['check_in_date', 'check_out_date']);
            $table->index('property_id');
            $table->index('created_by_staff_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_managed_bookings');
    }
};
