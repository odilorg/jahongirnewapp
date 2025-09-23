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
        Schema::table('cashier_shifts', function (Blueprint $table) {
            // Drop the problematic constraint
            // We'll handle uniqueness at the application level instead
            $table->dropUnique('unique_open_shift_per_drawer_user');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cashier_shifts', function (Blueprint $table) {
            // Restore the original constraint
            $table->unique(['cash_drawer_id', 'user_id', 'status'], 'unique_open_shift_per_drawer_user');
        });
    }
};
