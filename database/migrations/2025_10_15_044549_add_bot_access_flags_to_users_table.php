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
            // Add boolean flags to enable/disable bot access independently
            $table->boolean('pos_bot_enabled')->default(true)->after('telegram_booking_username');
            $table->boolean('booking_bot_enabled')->default(true)->after('pos_bot_enabled');
            
            // Add indexes for filtering
            $table->index('pos_bot_enabled');
            $table->index('booking_bot_enabled');
        });

        // For existing users: Enable bots based on current setup
        // POS bot: Enable if user has cashier/manager/super_admin role
        DB::statement("
            UPDATE users u
            INNER JOIN model_has_roles mhr ON u.id = mhr.model_id
            INNER JOIN roles r ON mhr.role_id = r.id
            SET u.pos_bot_enabled = 1
            WHERE r.name IN ('cashier', 'manager', 'super_admin')
            AND u.phone_number IS NOT NULL
        ");

        // Booking bot: Enable for all users with phone numbers
        DB::statement("
            UPDATE users 
            SET booking_bot_enabled = 1
            WHERE phone_number IS NOT NULL
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['pos_bot_enabled']);
            $table->dropIndex(['booking_bot_enabled']);
            $table->dropColumn(['pos_bot_enabled', 'booking_bot_enabled']);
        });
    }
};
