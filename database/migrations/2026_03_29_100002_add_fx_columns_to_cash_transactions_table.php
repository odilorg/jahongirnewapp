<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive migration: all new columns are nullable or carry safe defaults.
 * Existing rows remain fully valid. Old webhook-created USD rows default to
 * source_trigger='beds24_external' which correctly excludes them from drawer
 * truth queries.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guard: 2026_03_28_130002 added an overlapping set of columns to cash_transactions.
        // During migrate:fresh both migrations run; roll back 130002's additions first so
        // we can apply this replacement schema without duplicate-column errors.
        if (Schema::hasColumn('cash_transactions', 'booking_fx_sync_id')) {
            Schema::table('cash_transactions', function (Blueprint $table) {
                $table->dropForeign(['booking_fx_sync_id']);
                $table->dropForeign(['daily_exchange_rate_id']);
                $table->dropForeign(['override_approved_by']);
            });
            Schema::table('cash_transactions', function (Blueprint $table) {
                $table->dropColumn([
                    'booking_fx_sync_id',
                    'daily_exchange_rate_id',
                    'amount_presented_uzs',
                    'amount_presented_eur',
                    'amount_presented_rub',
                    'presented_currency',
                    'amount_presented_selected',
                    'is_override',
                    'override_tier',
                    'override_reason',
                    'override_approved_by',
                    'override_approved_at',
                    'presented_at',
                    'bot_session_id',
                ]);
            });
        }

        Schema::table('cash_transactions', function (Blueprint $table) {
            // Origin discriminator — the single most important new column.
            // Default 'beds24_external' correctly classifies all pre-existing rows.
            $table->string('source_trigger', 30)
                  ->default('beds24_external')
                  ->after('occurred_at');

            // FX sync back-reference (nullable — set only for bot/manual_admin rows)
            $table->foreignId('booking_fx_sync_id')
                  ->nullable()
                  ->after('source_trigger')
                  ->constrained('booking_fx_syncs')
                  ->nullOnDelete();

            // Rate reference for USD-equivalent conversion at settlement time
            $table->foreignId('exchange_rate_id')
                  ->nullable()
                  ->after('booking_fx_sync_id')
                  ->constrained('exchange_rates')
                  ->nullOnDelete();

            // Presentation snapshot (what the cashier saw on screen)
            $table->unsignedBigInteger('amount_presented_uzs')->nullable()->after('exchange_rate_id');
            $table->decimal('amount_presented_eur', 10, 2)->nullable()->after('amount_presented_uzs');
            $table->decimal('amount_presented_rub', 10, 2)->nullable()->after('amount_presented_eur');
            $table->decimal('amount_presented_usd', 10, 2)->nullable()->after('amount_presented_rub');
            $table->string('presented_currency', 5)->nullable()->after('amount_presented_usd');
            $table->decimal('amount_presented_selected', 12, 2)->nullable()->after('presented_currency');

            // USD-equivalent for settlement aggregation across currencies
            $table->decimal('usd_equivalent_paid', 10, 2)->nullable()->after('amount_presented_selected');

            // Override / tolerance audit trail
            $table->boolean('is_override')->default(false)->after('usd_equivalent_paid');
            $table->boolean('within_tolerance')->default(false)->after('is_override');
            $table->decimal('variance_pct', 6, 3)->nullable()->after('within_tolerance');
            $table->string('override_tier', 10)->default('none')->after('variance_pct');
            $table->text('override_reason')->nullable()->after('override_tier');
            $table->foreignId('override_approved_by')
                  ->nullable()
                  ->after('override_reason')
                  ->constrained('users')
                  ->nullOnDelete();
            $table->timestamp('override_approved_at')->nullable()->after('override_approved_by');
            // FK to fx_manager_approvals added in a later migration (table doesn't exist yet)
            $table->unsignedBigInteger('override_approval_id')->nullable()->after('override_approved_at');

            // Session and attribution
            $table->timestamp('presented_at')->nullable()->after('override_approval_id');
            $table->timestamp('recorded_at')->nullable()->after('presented_at');
            $table->string('bot_session_id', 120)->nullable()->after('recorded_at');

            // Beds24 sync back-reference (FK added later after beds24_payment_syncs exists)
            $table->unsignedBigInteger('beds24_payment_sync_id')->nullable()->after('bot_session_id');

            // Beds24-internal payment ID for external deduplication
            $table->string('beds24_payment_ref', 120)->nullable()->after('beds24_payment_sync_id');

            // Indexes
            $table->index('source_trigger');
            $table->index(['beds24_booking_id', 'source_trigger']);

            // Unique constraint: prevents duplicate external Beds24 payment rows
            // from repeated webhook deliveries for the same Beds24 payment ID
            $table->unique(['beds24_booking_id', 'beds24_payment_ref'], 'unique_beds24_payment_ref');
        });
    }

    public function down(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->dropUnique('unique_beds24_payment_ref');
            $table->dropIndex(['source_trigger']);
            $table->dropIndex(['beds24_booking_id', 'source_trigger']);
            $table->dropForeign(['booking_fx_sync_id']);
            $table->dropForeign(['exchange_rate_id']);
            $table->dropForeign(['override_approved_by']);
            $table->dropColumn([
                'source_trigger', 'booking_fx_sync_id', 'exchange_rate_id',
                'amount_presented_uzs', 'amount_presented_eur', 'amount_presented_rub',
                'amount_presented_usd', 'presented_currency', 'amount_presented_selected',
                'usd_equivalent_paid',
                'is_override', 'within_tolerance', 'variance_pct', 'override_tier',
                'override_reason', 'override_approved_by', 'override_approved_at',
                'override_approval_id',
                'presented_at', 'recorded_at', 'bot_session_id',
                'beds24_payment_sync_id', 'beds24_payment_ref',
            ]);
        });
    }
};
