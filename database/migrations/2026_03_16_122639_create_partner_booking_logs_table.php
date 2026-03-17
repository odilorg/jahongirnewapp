<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_booking_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('partner_id')->constrained('partners')->cascadeOnDelete();
            $table->string('telegram_chat_id', 50);          // who tapped the button
            $table->enum('action', ['request_sent', 'approved', 'rejected']);
            $table->string('booking_number', 100)->nullable(); // snapshot for easy reference
            $table->string('guest_name', 200)->nullable();     // snapshot
            $table->string('tour_date', 20)->nullable();       // snapshot
            $table->integer('pax')->nullable();                // snapshot
            $table->timestamp('actioned_at');                  // exact moment of action
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_booking_logs');
    }
};
