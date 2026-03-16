<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tour_reminder_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('booking_id')->nullable()->index();
            $table->unsignedBigInteger('guest_id')->nullable()->index();
            $table->string('channel', 20); // telegram | whatsapp
            $table->string('phone', 30)->nullable();
            $table->string('status', 20); // sent | failed | skipped
            $table->text('error_message')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();

            $table->index(['booking_id', 'channel', 'reminded_at'], 'trl_booking_channel_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tour_reminder_logs');
    }
};
