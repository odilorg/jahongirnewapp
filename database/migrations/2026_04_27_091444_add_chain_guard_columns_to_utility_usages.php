<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 chain-guard columns for `utility_usages`.
 *
 * Audit found 7 drift rows in the live data caused by free-form
 * meter_previous entry. These columns let the form auto-fill from the
 * prior reading, gate rare overrides behind an explicit toggle, and
 * distinguish a legitimate hardware reset from a typo.
 *
 * Additive only — no DROP, no NOT NULL on existing rows. Historical
 * data is preserved verbatim.
 *
 * - is_meter_reset                : true when meter_latest < meter_previous is intentional
 * - meter_previous_overridden     : true when operator manually edited the auto-filled value
 * - meter_previous_override_reason: free-text reason the operator typed when overriding
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('utility_usages', function (Blueprint $table) {
            $table->boolean('is_meter_reset')->default(false)->after('meter_difference');
            $table->boolean('meter_previous_overridden')->default(false)->after('is_meter_reset');
            $table->string('meter_previous_override_reason', 500)->nullable()->after('meter_previous_overridden');
        });
    }

    public function down(): void
    {
        Schema::table('utility_usages', function (Blueprint $table) {
            $table->dropColumn([
                'is_meter_reset',
                'meter_previous_overridden',
                'meter_previous_override_reason',
            ]);
        });
    }
};
