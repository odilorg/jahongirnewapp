<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tours', function (Blueprint $table) {
            // Telegram chat_id of the partner/supplier (e.g. Yurt Camp booking manager)
            // They receive a guest list notification the evening before the tour
            $table->string('partner_telegram_chat_id', 50)->nullable()->after('driver_brief');
            $table->string('partner_name', 100)->nullable()->after('partner_telegram_chat_id');
        });
    }

    public function down(): void
    {
        Schema::table('tours', function (Blueprint $table) {
            $table->dropColumn(['partner_telegram_chat_id', 'partner_name']);
        });
    }
};
