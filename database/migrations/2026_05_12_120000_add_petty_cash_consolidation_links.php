<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Petty Cash → Main Expenses monthly consolidation links + unpost trail.
 *
 * Additive, nullable-only. Columns:
 *   On cash_expenses:
 *     - consolidated_at                — "this row has been posted to main expenses"
 *     - consolidated_expense_id (FK)   — "the expenses.id row it became"
 *     - consolidation_unposted_at      — "operator reversed the posting at"
 *     - consolidation_unposted_reason  — required text when unposting
 *   On expenses:
 *     - cash_expense_id (FK)           — "this expense originated from cash_expense #N"
 *
 * Both FKs use ON DELETE SET NULL. Cash_expenses uses soft-delete in practice;
 * a hard-delete (rare, tinker-only) would clear the link rather than orphan it.
 *
 * No UNIQUE on either FK — idempotency is enforced at app level via
 * "WHERE consolidated_at IS NULL" before posting. Re-consolidation after
 * unpost creates a NEW expenses row (the soft-deleted prior row stays as
 * audit trail) and re-points consolidated_expense_id to the new id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_expenses', function (Blueprint $table) {
            $table->timestamp('consolidated_at')->nullable()->after('rejection_reason');
            $table->foreignId('consolidated_expense_id')
                ->nullable()
                ->after('consolidated_at')
                ->constrained('expenses')
                ->nullOnDelete();
            $table->timestamp('consolidation_unposted_at')->nullable()->after('consolidated_expense_id');
            $table->text('consolidation_unposted_reason')->nullable()->after('consolidation_unposted_at');
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
            $table->dropForeign(['consolidated_expense_id']);
            $table->dropColumn([
                'consolidated_at',
                'consolidated_expense_id',
                'consolidation_unposted_at',
                'consolidation_unposted_reason',
            ]);
        });
    }
};
