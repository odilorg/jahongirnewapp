<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_booking_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('authorized_staff')->onDelete('cascade');
            $table->bigInteger('chat_id');
            $table->bigInteger('message_id');
            
            // Request details
            $table->enum('request_type', [
                'check_availability',
                'create_booking',
                'modify_booking',
                'cancel_booking',
                'view_bookings'
            ]);
            $table->text('raw_message');
            $table->json('parsed_intent')->nullable();
            
            // Booking data
            $table->date('check_in_date')->nullable();
            $table->date('check_out_date')->nullable();
            $table->string('property_id', 100)->nullable();
            $table->string('property_name')->nullable();
            $table->string('room_id', 100)->nullable();
            $table->string('room_name')->nullable();
            $table->string('unit_name', 50)->nullable();
            
            // Guest information
            $table->string('guest_name')->nullable();
            $table->string('guest_email')->nullable();
            $table->string('guest_phone', 50)->nullable();
            $table->text('guest_notes')->nullable();
            
            // Pricing
            $table->decimal('total_price', 10, 2)->nullable();
            $table->string('currency', 3)->default('USD');
            
            // Beds24 integration
            $table->string('beds24_booking_id', 100)->nullable();
            $table->json('beds24_request')->nullable();
            $table->json('beds24_response')->nullable();
            
            // Status
            $table->enum('status', ['pending', 'completed', 'failed'])->default('pending');
            $table->text('error_message')->nullable();
            $table->decimal('response_time', 8, 2)->nullable();
            
            $table->timestamps();
            
            $table->index('staff_id');
            $table->index('status');
            $table->index('beds24_booking_id');
            $table->index('request_type');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_booking_requests');
    }
};
