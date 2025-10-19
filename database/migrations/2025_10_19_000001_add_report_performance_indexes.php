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
        // Optimize date range queries for cashier_shifts
        Schema::table('cashier_shifts', function (Blueprint $table) {
            $table->index('opened_at', 'idx_cashier_shifts_opened_at');
            $table->index(['status', 'opened_at'], 'idx_cashier_shifts_status_opened');
            $table->index('closed_at', 'idx_cashier_shifts_closed_at');
            $table->index('approved_at', 'idx_cashier_shifts_approved_at');
            $table->index('rejected_at', 'idx_cashier_shifts_rejected_at');
        });

        // Optimize transaction queries
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->index('occurred_at', 'idx_cash_transactions_occurred_at');
            $table->index(['cashier_shift_id', 'occurred_at'], 'idx_cash_transactions_shift_occurred');
            $table->index(['currency', 'occurred_at'], 'idx_cash_transactions_currency_occurred');
            $table->index('type', 'idx_cash_transactions_type');
            $table->index('category', 'idx_cash_transactions_category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cashier_shifts', function (Blueprint $table) {
            $table->dropIndex('idx_cashier_shifts_opened_at');
            $table->dropIndex('idx_cashier_shifts_status_opened');
            $table->dropIndex('idx_cashier_shifts_closed_at');
            $table->dropIndex('idx_cashier_shifts_approved_at');
            $table->dropIndex('idx_cashier_shifts_rejected_at');
        });

        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->dropIndex('idx_cash_transactions_occurred_at');
            $table->dropIndex('idx_cash_transactions_shift_occurred');
            $table->dropIndex('idx_cash_transactions_currency_occurred');
            $table->dropIndex('idx_cash_transactions_type');
            $table->dropIndex('idx_cash_transactions_category');
        });
    }
};
