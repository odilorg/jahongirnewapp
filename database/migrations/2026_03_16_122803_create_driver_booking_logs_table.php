<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_booking_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
            $table->string('telegram_chat_id', 50);
            $table->enum('action', ['request_sent', 'confirmed', 'rejected']);
            $table->string('booking_number', 100)->nullable();
            $table->string('guest_name', 200)->nullable();
            $table->string('tour_date', 20)->nullable();
            $table->integer('pax')->nullable();
            $table->timestamp('actioned_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_booking_logs');
    }
};
