<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * L-004 follow-up: belt-and-braces constraints on ledger_entries.
 *
 *  1. CHECK (amount >= 0) — MySQL 8 enforces. The DTO already requires
 *     amount > 0; this guards against any future path that tries to
 *     skip the DTO (database refuses to write a negative amount).
 *
 *  2. Combined index (booking_inquiry_id, occurred_at) — speeds up
 *     per-booking timeline queries (guest balance views, L-013 reports).
 *
 *  3. Combined index (beds24_booking_id, occurred_at) — same pattern
 *     for hotel-side booking timelines.
 *
 * Additive. ledger_entries is empty in all environments; this migration
 * cannot fail on live data.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE ledger_entries '
            . 'ADD CONSTRAINT ledger_entries_amount_non_negative_check '
            . 'CHECK (amount >= 0)'
        );

        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->index(
                ['booking_inquiry_id', 'occurred_at'],
                'ledger_entries_booking_inquiry_occurred_at_index'
            );
            $table->index(
                ['beds24_booking_id', 'occurred_at'],
                'ledger_entries_beds24_booking_occurred_at_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropIndex('ledger_entries_booking_inquiry_occurred_at_index');
            $table->dropIndex('ledger_entries_beds24_booking_occurred_at_index');
        });

        DB::statement(
            'ALTER TABLE ledger_entries DROP CHECK ledger_entries_amount_non_negative_check'
        );
    }
};
