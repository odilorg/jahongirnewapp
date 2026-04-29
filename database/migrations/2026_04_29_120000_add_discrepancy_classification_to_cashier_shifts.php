<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C1.1 — additive columns for shift-close discrepancy classification.
 *
 * Both columns are nullable; populated only at close time by a future
 * commit (C1.2 wires CashierShiftService to write them). Existing rows
 * are untouched.
 *
 * - discrepancy_tier: cast to App\Enums\OverrideTier (none|cashier|manager|blocked)
 * - discrepancy_severity_uzs: sum of |Δcurrency| × FX rate, in UZS
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cashier_shifts', function (Blueprint $t) {
            $t->string('discrepancy_tier', 16)->nullable()->after('discrepancy_reason');
            $t->decimal('discrepancy_severity_uzs', 15, 2)->nullable()->after('discrepancy_tier');
        });
    }

    public function down(): void
    {
        Schema::table('cashier_shifts', function (Blueprint $t) {
            $t->dropColumn(['discrepancy_tier', 'discrepancy_severity_uzs']);
        });
    }
};
