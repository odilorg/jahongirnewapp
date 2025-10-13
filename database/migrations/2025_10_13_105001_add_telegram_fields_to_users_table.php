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
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone_number', 50)->nullable()->after('email');
            $table->bigInteger('telegram_user_id')->nullable()->unique()->after('phone_number');
            $table->string('telegram_username')->nullable()->after('telegram_user_id');
            $table->timestamp('last_active_at')->nullable()->after('telegram_username');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone_number', 'telegram_user_id', 'telegram_username', 'last_active_at']);
        });
    }
};
