<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * NOTE: This table is append-only — admins cannot delete records.
     * Cancellations are tracked via booking_status field.
     */
    public function up(): void
    {
        Schema::create('beds24_bookings', function (Blueprint $table) {
            $table->id();
            $table->string('beds24_booking_id')->unique()->comment('Unique booking ID from Beds24');
            $table->string('property_id')->comment('Beds24 property ID (41097 or 172793)');
            $table->string('room_id')->nullable()->comment('Beds24 room ID');
            $table->string('room_name')->nullable();
            $table->string('guest_name')->nullable();
            $table->string('guest_email')->nullable();
            $table->string('guest_phone')->nullable();
            $table->string('channel')->nullable()->comment('booking.com, airbnb, walk-in, direct, etc.');
            $table->date('arrival_date')->nullable();
            $table->date('departure_date')->nullable();
            $table->unsignedSmallInteger('num_adults')->default(1);
            $table->unsignedSmallInteger('num_children')->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('currency', 10)->default('USD');
            $table->string('payment_status', 20)->default('pending')
                ->comment('pending, paid, partial');
            $table->string('payment_type', 20)->nullable()
                ->comment('cash, card, transfer, mixed');
            $table->string('booking_status', 30)->default('confirmed')
                ->comment('confirmed, cancelled, modified, no_show, awaiting_payment');
            $table->string('original_status', 30)->nullable()
                ->comment('Status when first received from webhook');
            $table->decimal('invoice_balance', 12, 2)->default(0)
                ->comment('Outstanding balance from Beds24 invoice');
            $table->json('beds24_raw_data')->nullable()
                ->comment('Full raw payload from Beds24 webhook');
            $table->timestamp('admin_confirmed_at')->nullable()
                ->comment('When a hotel admin confirmed payment');
            $table->foreignId('admin_id')->nullable()->constrained('users')
                ->nullOnDelete()->comment('Which admin confirmed payment');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->timestamp('cancelled_at')->nullable();

            // Indexes for common queries
            $table->index('property_id');
            $table->index('arrival_date');
            $table->index('departure_date');
            $table->index('booking_status');
            $table->index('payment_status');
            $table->index('channel');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('beds24_bookings');
    }
};
