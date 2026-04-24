<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_inquiries', function (Blueprint $table) {
            $table->decimal('amount_online_usd', 10, 2)->nullable()->after('net_revenue')
                ->comment('Amount requested via online payment link (not necessarily collected yet)');

            $table->decimal('amount_cash_usd', 10, 2)->default(0)->after('amount_online_usd')
                ->comment('Amount expected in cash at pickup (not necessarily collected yet)');

            $table->enum('payment_split', ['full_online', 'partial'])->default('full_online')->after('amount_cash_usd');
        });

        // Backfill: rows that already have a live payment link inherit the full quote
        // as the online portion. Excludes cancelled/spam (stale links) and rows with
        // no price_quoted (nothing meaningful to backfill).
        DB::table('booking_inquiries')
            ->whereNotNull('payment_link')
            ->whereNotNull('price_quoted')
            ->whereNotIn('status', ['cancelled', 'spam'])
            ->update([
                'amount_online_usd' => DB::raw('price_quoted'),
                'amount_cash_usd'   => 0,
                'payment_split'     => 'full_online',
            ]);
    }

    public function down(): void
    {
        Schema::table('booking_inquiries', function (Blueprint $table) {
            $table->dropColumn(['amount_online_usd', 'amount_cash_usd', 'payment_split']);
        });
    }
};
