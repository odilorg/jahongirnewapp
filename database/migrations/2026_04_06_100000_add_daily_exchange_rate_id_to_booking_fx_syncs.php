<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add daily_exchange_rate_id to booking_fx_syncs.
 *
 * Background: FxSyncService::pushNow() writes 'daily_exchange_rate_id' (FK to daily_exchange_rates)
 * but the 2026_03_29 migration created the table with 'exchange_rate_id' (FK to exchange_rates).
 * This mismatch caused daily_exchange_rate_id to be silently dropped by mass-assignment protection,
 * leaving it null and triggering a TypeError in PaymentPresentation::__construct().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_fx_syncs', function (Blueprint $table) {
            $table->unsignedBigInteger('daily_exchange_rate_id')
                  ->nullable()
                  ->after('fx_rate_date');

            $table->foreign('daily_exchange_rate_id')
                  ->references('id')
                  ->on('daily_exchange_rates')
                  ->nullOnDelete();
        });

        // Backfill: match existing sync rows to their daily_exchange_rate by rate_date
        DB::statement('
            UPDATE booking_fx_syncs bfs
            JOIN daily_exchange_rates der ON der.rate_date = bfs.fx_rate_date
            SET bfs.daily_exchange_rate_id = der.id
            WHERE bfs.daily_exchange_rate_id IS NULL
        ');
    }

    public function down(): void
    {
        Schema::table('booking_fx_syncs', function (Blueprint $table) {
            $table->dropForeign(['daily_exchange_rate_id']);
            $table->dropColumn('daily_exchange_rate_id');
        });
    }
};
