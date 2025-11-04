<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_booking_sessions', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('telegram_user_id')->index();
            $table->bigInteger('chat_id')->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('state', 50)->default('awaiting_auth');
            $table->json('data')->nullable();
            $table->string('language', 5)->default('en');
            $table->timestamp('last_activity_at')->nullable()->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
            
            $table->unique(['telegram_user_id', 'chat_id'], 'unique_telegram_booking_session');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_booking_sessions');
    }
};
