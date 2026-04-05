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
        // Replaces the 2025_10_14_223335 version (different schema: added unique on chat_id,
        // dropped telegram_user_id/last_activity_at/expires_at columns).
        Schema::dropIfExists('telegram_pos_sessions');

        Schema::create('telegram_pos_sessions', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('chat_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('language', 10)->default('en');
            $table->string('state', 50)->default('main_menu');
            $table->json('data')->nullable();
            $table->timestamps();

            $table->index('chat_id');
            $table->index('updated_at');
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
