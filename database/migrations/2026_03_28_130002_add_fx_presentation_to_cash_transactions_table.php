<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds FX-presentation audit columns to cash_transactions.
 *
 * These columns link a recorded cashier payment to the exact booking_fx_sync
 * row and daily_exchange_rate snapshot that were used, and preserve what amounts
 * the bot showed the cashier versus what was actually collected.
 *
 * This closes the reconciliation chain:
 *   daily_exchange_rates → booking_fx_syncs → cash_transactions
 *
 * All amounts are decimal(12,2) for forward-compatibility even though current
 * business rules round to whole units.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table) {
            // Link to the exact sync row the bot presented
            $table->foreignId('booking_fx_sync_id')
                  ->nullable()
                  ->constrained('booking_fx_syncs');

            // Direct link to rate snapshot for reporting without joining through sync
            $table->foreignId('daily_exchange_rate_id')
                  ->nullable()
                  ->constrained('daily_exchange_rates');

            // Snapshot of all three amounts shown to cashier (frozen at presentation time)
            $table->decimal('amount_presented_uzs', 12, 2)->nullable();
            $table->decimal('amount_presented_eur', 12, 2)->nullable();
            $table->decimal('amount_presented_rub', 12, 2)->nullable();

            // Which currency the cashier selected + the snapshot amount for that currency
            // Avoids inferring from the three columns above which one was relevant
            $table->string('presented_currency', 3)->nullable();
            $table->decimal('amount_presented_selected', 12, 2)->nullable();

            // Override tracking
            $table->boolean('is_override')->default(false);
            $table->enum('override_tier', ['none', 'cashier', 'manager', 'blocked'])->default('none');
            $table->text('override_reason')->nullable();
            $table->foreignId('override_approved_by')->nullable()->constrained('users');
            $table->timestamp('override_approved_at')->nullable();

            // When bot showed amounts vs when cashier confirmed — gap = conversation duration
            $table->timestamp('presented_at')->nullable();

            // Which Telegram conversation produced this record
            $table->string('bot_session_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('booking_fx_sync_id');
            $table->dropConstrainedForeignId('daily_exchange_rate_id');
            $table->dropConstrainedForeignId('override_approved_by');
            $table->dropColumn([
                'amount_presented_uzs',
                'amount_presented_eur',
                'amount_presented_rub',
                'presented_currency',
                'amount_presented_selected',
                'is_override',
                'override_tier',
                'override_reason',
                'override_approved_at',
                'presented_at',
                'bot_session_id',
            ]);
        });
    }
};
