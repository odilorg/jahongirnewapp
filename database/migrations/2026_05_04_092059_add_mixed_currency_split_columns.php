<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1.5.1 — Mixed-currency split foundation.
 *
 * Two columns:
 *
 *   - base_currency_for_split (varchar 3, nullable)
 *     The booking's commercial-truth currency at recording time.
 *     Set ONLY for rows that are part of a mixed-currency journal
 *     (one leg paid in UZS, the other in USD/EUR, etc.).
 *     NULL for everything else: standalone payments, exchanges,
 *     and same-currency splits.
 *     v1.5 hard-codes this to the operator-selected presentation
 *     currency at journal creation — there is intentionally NO
 *     operator override (prevents silent favorable manipulation).
 *
 *   - journal_status (varchar 24, default 'complete')
 *     Lifecycle state of the parent journal_entry_id, denormalised
 *     onto each leg for cheap "show me orphan / failed journals"
 *     queries without a self-join.
 *     Values:
 *       'complete'           — all legs persisted, sum-lock satisfied
 *       'pending_second_leg' — leg 1 saved, leg 2 not yet recorded
 *                              (future deposit + balance workflow;
 *                              not used in v1.5 itself but reserved)
 *       'voided'             — entire journal cancelled / reversed
 *       'failed_sumlock'     — recording aborted because sum-lock
 *                              didn't reconcile (rare; for audit only)
 *
 * Both columns are nullable / defaulted, so this migration is purely
 * additive. No behaviour change on existing rows; all 78 existing
 * rows default to journal_status='complete'.
 *
 * See docs/architecture/PHASE_1_5_PLAN.md for the doctrine and
 * architectural invariants this column set serves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->string('base_currency_for_split', 3)
                ->nullable()
                ->index()
                ->after('payment_group_type');

            $table->string('journal_status', 24)
                ->default('complete')
                ->index()
                ->after('base_currency_for_split');
        });
    }

    public function down(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->dropIndex(['base_currency_for_split']);
            $table->dropIndex(['journal_status']);
            $table->dropColumn(['base_currency_for_split', 'journal_status']);
        });
    }
};
