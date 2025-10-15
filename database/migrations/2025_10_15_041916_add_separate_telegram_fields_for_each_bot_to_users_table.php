<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Add separate Telegram fields for POS bot
            $table->bigInteger('telegram_pos_user_id')->nullable()->after('telegram_user_id');
            $table->string('telegram_pos_username')->nullable()->after('telegram_username');
            
            // Add separate Telegram fields for Booking bot
            $table->bigInteger('telegram_booking_user_id')->nullable()->after('telegram_pos_username');
            $table->string('telegram_booking_username')->nullable()->after('telegram_booking_user_id');
            
            // Add indexes for performance
            $table->index('telegram_pos_user_id', 'idx_telegram_pos_user_id');
            $table->index('telegram_booking_user_id', 'idx_telegram_booking_user_id');
        });

        // Migrate existing data from generic fields to POS bot fields
        // Assuming existing telegram_user_id data is from POS bot (most recent)
        DB::statement('
            UPDATE users 
            SET telegram_pos_user_id = telegram_user_id,
                telegram_pos_username = telegram_username
            WHERE telegram_user_id IS NOT NULL
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Drop indexes first
            $table->dropIndex('idx_telegram_pos_user_id');
            $table->dropIndex('idx_telegram_booking_user_id');
            
            // Drop columns
            $table->dropColumn([
                'telegram_pos_user_id',
                'telegram_pos_username',
                'telegram_booking_user_id',
                'telegram_booking_username',
            ]);
        });
    }
};
