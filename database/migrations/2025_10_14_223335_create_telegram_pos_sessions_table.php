<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('telegram_pos_sessions', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('telegram_user_id')->index();
            $table->bigInteger('chat_id')->index();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('state')->default('idle'); // idle, awaiting_phone, authenticated, etc.
            $table->json('data')->nullable(); // conversation context
            $table->string('language')->default('en'); // en, ru, uz
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            
            // Indexes for performance
            $table->index(['telegram_user_id', 'user_id']);
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram_pos_sessions');
    }
};
