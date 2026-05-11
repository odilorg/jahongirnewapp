<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tier-1 safety additions for the hotel-ops `expenses` table:
 *   - `created_by` (nullable FK to users) — audit attribution. Matches
 *     the convention used by cash_expenses. Existing 2,358 rows stay
 *     NULL; ExpenseObserver populates it on new rows.
 *   - `deleted_at` (soft delete) — prevents accidental permanent loss
 *     of expense records from the admin UI.
 *
 * Both columns are nullable / additive — no backfill, no data movement,
 * no concurrent-write hazard. Safe to deploy without downtime.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            // foreignId without ->constrained() to match the existing
            // hotel_id pattern on this table (legacy convention, not
            // strictly correct — but adding a FK constraint here on
            // a 2,358-row legacy table risks unrelated breakage if any
            // existing row has hotel_id pointing nowhere).
            $table->foreignId('created_by')->nullable()->after('payment_type');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn('created_by');
            $table->dropSoftDeletes();
        });
    }
};
