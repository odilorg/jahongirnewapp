<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 follow-up: enforce one reading per (meter, date) at the DB layer.
 *
 * The MeterReadingChainService rejects same-date inserts at the model save
 * step, but two near-simultaneous form submits could both pass validation
 * before either has committed. Adding a UNIQUE index closes that race
 * unconditionally — the second writer hits a UniqueConstraintViolation
 * regardless of how it got there (form, tinker, batch, future API).
 *
 * Pre-flight (run on prod, jahongir VPS):
 *   No duplicate (meter_id, usage_date) pairs found in the 32 restored rows.
 *   Migration is safe to apply forward.
 *
 * Named constraint so down() is deterministic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('utility_usages', function (Blueprint $table) {
            $table->unique(
                ['meter_id', 'usage_date'],
                'utility_usages_meter_id_usage_date_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('utility_usages', function (Blueprint $table) {
            $table->dropUnique('utility_usages_meter_id_usage_date_unique');
        });
    }
};
