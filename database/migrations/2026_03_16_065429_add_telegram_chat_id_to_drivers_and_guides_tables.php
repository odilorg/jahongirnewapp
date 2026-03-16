<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->string('telegram_chat_id')->nullable()->unique()->after('phone02');
        });

        Schema::table('guides', function (Blueprint $table) {
            $table->string('telegram_chat_id')->nullable()->unique()->after('phone02');
        });
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->dropColumn('telegram_chat_id');
        });

        Schema::table('guides', function (Blueprint $table) {
            $table->dropColumn('telegram_chat_id');
        });
    }
};
