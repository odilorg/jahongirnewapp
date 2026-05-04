<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1.5.5 — FX variance recording.
 *
 * Real-world driver 2026-05-04: guest pays 50 USD + 370,000 UZS to settle
 * a 970,000 UZS booking. At system frozen rate ~12,300/USD that's
 * 985,000 UZS-equivalent — 15,000 UZS over the booking total. The hotel
 * absorbs that gap as FX gain because operator and guest negotiated
 * 12,000 UZS/USD at the desk. Strict sum-lock would reject; with
 * variance fields it's recorded as explicit accounting fact.
 *
 * Three columns, all nullable, populated only on the FIRST leg of a
 * mixed-currency journal where variance > silent tolerance:
 *
 *   - fx_variance_amount (decimal 12,2 nullable)
 *     Variance in the journal's base currency. Positive = hotel gain
 *     (legs total > booking total); negative = hotel loss.
 *
 *   - fx_variance_currency (varchar 3 nullable)
 *     Base currency the variance is denominated in. Always equals the
 *     row's base_currency_for_split when populated. Stored explicitly
 *     so reports can sum variance per currency without joining.
 *
 *   - fx_variance_reason (varchar 40 nullable, indexed)
 *     Structured enum from RequiresVarianceReason values:
 *       agreed_shop_rate    — operator/guest negotiated rate
 *       bill_denomination   — guest gave round bills, hotel rounded
 *       guest_overpay       — guest paid more than expected
 *       guest_underpay      — guest short, hotel absorbed
 *       rate_drift          — frozen rate diverged from settlement reality
 *       other               — free-text follow-up required (in notes)
 *     Indexed for the daily audit's variance digest aggregation.
 *
 * Doctrine (locked in PHASE_1_5_PLAN.md):
 *   - Variance bands: 0-1% silent / 1-3% reason / 3-5% manager / >5% reject
 *   - Variance is recorded explicitly, never hidden in fudged amounts
 *   - Each leg keeps native amount truth; variance lives at journal level
 *   - Frozen presentation rate remains canonical; variance is the
 *     operational exception layer
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->decimal('fx_variance_amount', 12, 2)
                ->nullable()
                ->after('journal_status');

            $table->string('fx_variance_currency', 3)
                ->nullable()
                ->after('fx_variance_amount');

            $table->string('fx_variance_reason', 40)
                ->nullable()
                ->index()
                ->after('fx_variance_currency');
        });
    }

    public function down(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->dropIndex(['fx_variance_reason']);
            $table->dropColumn(['fx_variance_amount', 'fx_variance_currency', 'fx_variance_reason']);
        });
    }
};
