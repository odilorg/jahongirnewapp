<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Session table for operator-driven manual tour booking via Telegram.
 *
 * One session per chat_id. State machine drives step-by-step data collection.
 * On completion the session's data is handed to WebsiteBookingService::createFromWebsite()
 * and the session is reset to idle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operator_booking_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('chat_id', 32)->unique();   // Telegram chat_id (string for safety)
            $table->string('state', 50)->default('idle');
            $table->json('data')->nullable();           // Partial booking in progress
            $table->timestamps();                       // updated_at used as activity timestamp
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_booking_sessions');
    }
};
