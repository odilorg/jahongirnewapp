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
        // Guard: these columns were already added by the 2025_10_13_105001 migration.
        // This migration is a duplicate that was created in error; skip if already present.
        if (Schema::hasColumn('users', 'phone_number')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('phone_number')->nullable()->after('email');
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
        // No-op: the 2025_10_13_105001 migration owns and drops these columns.
    }
};
