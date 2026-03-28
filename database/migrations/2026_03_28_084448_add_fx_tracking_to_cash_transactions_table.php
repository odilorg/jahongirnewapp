<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds FX (foreign-exchange) tracking columns to cash_transactions.
 *
 * Context:
 *   Beds24 bookings are invoiced in USD. Guests often pay in UZS.
 *   The UZS amount (column: amount) is what lands in the physical vault and
 *   determines the petty-cash balance. The columns here store the matching USD
 *   booking reference and the rates used, enabling two useful variances:
 *
 *     collection_variance = amount_uzs - (booking_amount_usd × applied_exchange_rate)
 *       → rounding / negotiation / data-entry error
 *
 *     fx_variance = amount_uzs - (booking_amount_usd × reference_exchange_rate)
 *       → management signal: how much above/below the official CBU rate was collected
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table) {
            // Booking-side reference (from Beds24, populated on cross-currency payments)
            $table->string('booking_currency', 3)->nullable()->after('room_number');
            $table->decimal('booking_amount', 12, 2)->nullable()->after('booking_currency');

            // Rate the cashier actually applied (entered or accepted from suggestion)
            $table->decimal('applied_exchange_rate', 15, 4)->nullable()->after('booking_amount');

            // Official benchmark rate, auto-fetched (CBU → er-api → floatrates)
            $table->decimal('reference_exchange_rate', 15, 4)->nullable()->after('applied_exchange_rate');
            $table->string('reference_rate_source', 20)->nullable()->after('reference_exchange_rate');
            $table->date('reference_rate_date')->nullable()->after('reference_rate_source');
        });
    }

    public function down(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->dropColumn([
                'booking_currency',
                'booking_amount',
                'applied_exchange_rate',
                'reference_exchange_rate',
                'reference_rate_source',
                'reference_rate_date',
            ]);
        });
    }
};
