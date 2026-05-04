<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1.7.1 — Bulk group payment audit substrate.
 *
 * Adds a JSON snapshot column persisting the per-sibling distribution
 * computed at payment time. Critical for support questions of the form
 * "why did room B get 181.61 USD instead of 181.60?" — without this
 * column the rounding decisions would be invisible after the fact.
 *
 * Populated only on the FIRST leg of a journal where
 * payment_group_type='group_bulk'. Schema:
 *   {
 *     "master_booking_id": "82668021",
 *     "group_total_currency": "USD",
 *     "group_total_amount": 368.40,
 *     "group_total_at_payment_time": 368.40,    // sum of siblings' invoiced totals at submit
 *     "siblings": [
 *       {"booking_id": "82668021", "invoice_total": 186.80, "share": 186.80, "leg_tx_id": 99},
 *       {"booking_id": "82668022", "invoice_total": 181.60, "share": 181.60, "leg_tx_id": 100}
 *     ],
 *     "rounding_method": "largest_remainder"
 *   }
 *
 * NULL on every other row (single payments, splits, exchanges,
 * mixed-currency journals — none have a sibling distribution to
 * record).
 *
 * Architectural invariants from PHASE_1_5_PLAN.md preserved:
 *   - Group convenience never reduces per-booking truth
 *     (each sibling still gets its own cash_transactions row)
 *   - Snapshot lives at journal level, denormalised onto first leg
 *     for cheap "show me distribution" queries without joins
 *   - Frozen at submit time (group composition freeze)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->json('group_distribution_snapshot')
                ->nullable()
                ->after('fx_variance_reason');
        });
    }

    public function down(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->dropColumn('group_distribution_snapshot');
        });
    }
};
