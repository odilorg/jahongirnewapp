<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 of "Beds24 admin cash counts as drawer truth".
 *
 * Adds five additive columns to cash_transactions so the webhook
 * handler can mark beds24_external rows that pass all five guards
 * (cash-method allow-list, after-cutoff, no-matching-bot-row,
 * open-shift, non-null booking_id) as drawer truth — and so the
 * Filament reconciliation page can audit-flip rows that didn't.
 *
 * Default `counts_as_drawer_truth = false` preserves existing
 * scopeDrawerTruth behaviour byte-for-byte: no historical row is
 * retroactively reclassified.
 *
 * See `docs/FIXES.md` (entry: 2026-05-11 — beds24 admin cash drawer-
 * truth Phase 1) for the rollback plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->boolean('counts_as_drawer_truth')
                ->default(false)
                ->after('source_trigger');

            $table->string('drawer_truth_excluded_reason', 64)
                ->nullable()
                ->after('counts_as_drawer_truth');

            $table->foreignId('drawer_truth_flipped_by_user_id')
                ->nullable()
                ->after('drawer_truth_excluded_reason')
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('drawer_truth_flipped_at')
                ->nullable()
                ->after('drawer_truth_flipped_by_user_id');

            $table->string('drawer_truth_flip_note', 255)
                ->nullable()
                ->after('drawer_truth_flipped_at');

            // Composite index supports the two hot queries:
            //   - scopeDrawerTruth: WHERE counts_as_drawer_truth=true AND source_trigger='beds24_external'
            //   - Filament reconciliation page: WHERE counts_as_drawer_truth=false AND source_trigger='beds24_external'
            $table->index(
                ['counts_as_drawer_truth', 'source_trigger'],
                'cash_transactions_drawer_truth_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->dropIndex('cash_transactions_drawer_truth_idx');
            $table->dropConstrainedForeignId('drawer_truth_flipped_by_user_id');
            $table->dropColumn([
                'counts_as_drawer_truth',
                'drawer_truth_excluded_reason',
                'drawer_truth_flipped_at',
                'drawer_truth_flip_note',
            ]);
        });
    }
};
