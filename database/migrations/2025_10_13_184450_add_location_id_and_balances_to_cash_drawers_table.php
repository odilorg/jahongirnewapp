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
        Schema::table('cash_drawers', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->after('id')->constrained()->onDelete('cascade');
            $table->json('balances')->nullable()->after('is_active');

            // Index for location queries
            $table->index('location_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cash_drawers', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropColumn(['location_id', 'balances']);
        });
    }
};
