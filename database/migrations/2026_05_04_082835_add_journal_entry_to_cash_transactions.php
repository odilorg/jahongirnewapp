<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal Entry Foundation v1 — adds the substrate that enables one
 * guest payment event to span multiple ledger rows while still being
 * recognised as a single accounting transaction.
 *
 * Two columns:
 *   - journal_entry_id   UUID-like string (40 chars). NULL for legacy
 *     single-row entries; populated for any logical grouping going
 *     forward (split payments now; reversals / exchange pairs / refunds
 *     in later phases). Indexed for cheap "all rows in this journal"
 *     lookups by the duplicate guard and reconciliation queries.
 *
 *   - payment_group_type Classifies the relationship of rows under the
 *     same journal_entry_id. Phase-1 values:
 *       'single'   — standalone (the old default; kept as the default
 *                    column value so historical rows classify cleanly)
 *       'split'    — guest paid this booking via 2+ instruments (cash
 *                    + card / cash + transfer / etc). Each instrument
 *                    is its own row; all share one journal_entry_id.
 *       'exchange' — currency conversion in/out pair (operator
 *                    workaround for FX); existing pairs can be back-
 *                    filled later via batch script.
 *       'reversal' — opposite-sign correcting entry (Phase 2).
 *       'refund'   — guest-side reversal (Phase 2).
 *
 * Both columns are nullable / defaulted so the migration is purely
 * additive — no behaviour change on existing rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->string('journal_entry_id', 40)->nullable()->index()->after('beds24_payment_ref');
            $table->string('payment_group_type', 16)->default('single')->index()->after('journal_entry_id');
        });
    }

    public function down(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->dropIndex(['journal_entry_id']);
            $table->dropIndex(['payment_group_type']);
            $table->dropColumn(['journal_entry_id', 'payment_group_type']);
        });
    }
};
