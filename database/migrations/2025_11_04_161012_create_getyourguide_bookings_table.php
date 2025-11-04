<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('getyourguide_bookings', function (Blueprint $table) {
            $table->id();

            // Email Metadata
            $table->string('email_message_id')->unique();
            $table->string('email_subject');
            $table->timestamp('email_date');
            $table->longText('raw_email_body');
            $table->longText('raw_email_html')->nullable();

            // Tour Relationship (for mapping to internal tour system)
            $table->foreignId('tour_id')->nullable()->constrained('tours')->nullOnDelete();

            // Extracted Booking Data
            $table->string('booking_reference')->nullable()->index();
            $table->date('booking_date')->nullable();
            $table->string('tour_name')->nullable();
            $table->date('tour_date')->nullable()->index();
            $table->time('tour_time')->nullable();
            $table->string('duration')->nullable();

            // Guest Information
            $table->string('guest_name')->nullable();
            $table->string('guest_email')->nullable();
            $table->string('guest_phone')->nullable();
            $table->integer('number_of_guests')->nullable();
            $table->integer('adults')->nullable();
            $table->integer('children')->nullable();

            // Pickup Details
            $table->text('pickup_location')->nullable();
            $table->time('pickup_time')->nullable();
            $table->text('special_requirements')->nullable();

            // Financial
            $table->decimal('total_price', 10, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('payment_status')->nullable();

            // Processing Status
            $table->enum('processing_status', ['pending', 'processing', 'completed', 'failed'])->default('pending')->index();
            $table->integer('ai_extraction_attempts')->default(0);
            $table->json('ai_response')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('notified_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('getyourguide_bookings');
    }
};
