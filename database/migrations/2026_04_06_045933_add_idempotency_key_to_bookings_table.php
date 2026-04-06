<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds idempotency_key to bookings so duplicate website form submissions
 * (double-clicks, network retries) are rejected at the DB level.
 *
 * Key is a SHA-256 hash of: email + tour_id + date (YYYY-MM-DD).
 * NULL for bookings created by other sources (GYG, operator CLI, etc.)
 * so the unique constraint only applies to website bookings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('idempotency_key', 64)->nullable()->unique()->after('booking_number');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
