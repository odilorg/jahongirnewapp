<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * telegram_user_id was NOT NULL with no default, but several bot controllers
 * (HousekeepingBot, TelegramPosController) create sessions without it.
 * Making it nullable is the correct semantic: the column is optional until
 * the user authenticates via a contact-share that includes their Telegram ID.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_pos_sessions', function (Blueprint $table) {
            $table->bigInteger('telegram_user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('telegram_pos_sessions', function (Blueprint $table) {
            $table->bigInteger('telegram_user_id')->nullable(false)->change();
        });
    }
};
