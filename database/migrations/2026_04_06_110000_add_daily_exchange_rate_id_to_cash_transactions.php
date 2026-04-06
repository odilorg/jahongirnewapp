<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add daily_exchange_rate_id to cash_transactions.
 *
 * Same root bug as booking_fx_syncs: BotPaymentService::recordPayment() writes
 * 'daily_exchange_rate_id' but the column never existed. Mass-assignment protection
 * silently dropped every write, so every CashTransaction lost its FX rate link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('daily_exchange_rate_id')
                  ->nullable()
                  ->after('exchange_rate_id');

            $table->foreign('daily_exchange_rate_id')
                  ->references('id')
                  ->on('daily_exchange_rates')
                  ->nullOnDelete();
        });

        // Backfill via booking_fx_syncs join — transactions linked to a sync row
        // inherit that sync's daily_exchange_rate_id.
        DB::statement('
            UPDATE cash_transactions ct
            JOIN booking_fx_syncs bfs ON bfs.id = ct.booking_fx_sync_id
            SET ct.daily_exchange_rate_id = bfs.daily_exchange_rate_id
            WHERE ct.booking_fx_sync_id IS NOT NULL
              AND ct.daily_exchange_rate_id IS NULL
              AND bfs.daily_exchange_rate_id IS NOT NULL
        ');
    }

    public function down(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->dropForeign(['daily_exchange_rate_id']);
            $table->dropColumn('daily_exchange_rate_id');
        });
    }
};
