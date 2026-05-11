<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Petty Cash → Main Expenses monthly consolidation links.
 *
 * Additive, nullable-only. Two columns:
 *   - cash_expenses.consolidated_at    — "this petty-cash row has been posted
 *                                         into the main expenses ledger"
 *   - expenses.cash_expense_id (FK)    — "this hotel-ops expense row originated
 *                                         from this petty-cash row"
 *
 * FK uses ON DELETE SET NULL so a hard-delete of a cash_expense (rare —
 * cash_expenses uses soft delete) doesn't orphan the consolidated expense row;
 * the link clears instead.
 *
 * No backfill, no NOT NULL, no UNIQUE — kept intentionally small. Idempotency
 * is enforced at app level via "WHERE consolidated_at IS NULL" before posting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_expenses', function (Blueprint $table) {
            $table->timestamp('consolidated_at')->nullable()->after('rejection_reason');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('cash_expense_id')
                ->nullable()
                ->after('hotel_id')
                ->constrained('cash_expenses')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropForeign(['cash_expense_id']);
            $table->dropColumn('cash_expense_id');
        });

        Schema::table('cash_expenses', function (Blueprint $table) {
            $table->dropColumn('consolidated_at');
        });
    }
};
