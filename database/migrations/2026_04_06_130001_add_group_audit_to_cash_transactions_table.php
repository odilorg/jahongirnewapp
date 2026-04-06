<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 — Group payment audit columns on cash_transactions.
 *
 * When a cashier records payment for a grouped booking (multiple rooms),
 * we persist group context alongside the transaction so that:
 *
 *   (a) Finance/audit can understand why one transaction covers multiple rooms
 *   (b) Duplicate-payment guard can query by group master across sibling booking IDs
 *   (c) Reporting can aggregate group payments correctly
 *
 * All four columns are nullable — existing rows and standalone-booking payments
 * are unaffected (null = standalone, no group context).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->string('group_master_booking_id', 30)
                ->nullable()
                ->after('beds24_booking_id')
                ->comment('Group master booking ID when payment covers a multi-room group');

            $table->boolean('is_group_payment')
                ->default(false)
                ->after('group_master_booking_id')
                ->comment('True when this transaction covers a Beds24 group booking total');

            $table->unsignedSmallInteger('group_size_expected')
                ->nullable()
                ->after('is_group_payment')
                ->comment('Expected group size (from booking_group_size) at time of payment');

            $table->unsignedSmallInteger('group_size_local')
                ->nullable()
                ->after('group_size_expected')
                ->comment('Locally-synced group size seen at time of payment (may differ if partial sync)');

            $table->index('group_master_booking_id', 'idx_cash_tx_group_master_booking_id');
        });
    }

    public function down(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->dropIndex('idx_cash_tx_group_master_booking_id');
            $table->dropColumn([
                'group_master_booking_id',
                'is_group_payment',
                'group_size_expected',
                'group_size_local',
            ]);
        });
    }
};
