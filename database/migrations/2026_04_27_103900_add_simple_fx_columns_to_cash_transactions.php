<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FX simplification — Phase 1 additive migration.
 *
 * See docs/architecture/fx-simplification-plan.md for the full design
 * and Appendix A for the read-only audit that justified this shape.
 *
 * Adds five columns + one composite index. All columns are nullable /
 * defaulted so the 42 existing rows in cash_transactions are not
 * violated. No data is rewritten.
 *
 * Phase 1 contract: every NEW CashTransaction row populates these
 * columns alongside the legacy fields. Old code path (tier system) is
 * still source of truth. No reader has switched yet.
 *
 * Locked thresholds (from §1 of the plan, after the 2026-04-27 review):
 *   - 3% silent band
 *   - 15% reject threshold
 *   Both live in config('cashier.fx.*'), not in this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table) {
            // Three rate fields — system rate, what the cashier actually
            // used, and the signed deviation. deviation_pct is stored
            // (not derived) because admin filters need to sort on it
            // without recomputation.
            $table->decimal('reference_rate', 14, 4)->nullable()->after('related_amount');
            $table->decimal('actual_rate',    14, 4)->nullable()->after('reference_rate');
            $table->decimal('deviation_pct',   7, 4)->nullable()->after('actual_rate'); // signed

            // Audit trail for the override case. NOTE: `override_reason`
            // is NOT added here — it already exists in the schema as
            // part of the legacy tier system. The new flow reuses that
            // column rather than introducing a duplicate.
            $table->boolean('was_overridden')->default(false)->after('deviation_pct');

            // Composite index supports the admin "show overridden,
            // sorted recent-first" filter directly.
            $table->index(['was_overridden', 'created_at'], 'cash_tx_was_overridden_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->dropIndex('cash_tx_was_overridden_created_idx');
            $table->dropColumn([
                'reference_rate',
                'actual_rate',
                'deviation_pct',
                'was_overridden',
                // override_reason is intentionally not dropped — it
                // belongs to the legacy schema that predates this
                // migration.
            ]);
        });
    }
};
