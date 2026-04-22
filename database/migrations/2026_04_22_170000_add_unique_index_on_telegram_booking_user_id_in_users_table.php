<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10.8 — audit finding B8.
 *
 * Replaces the non-unique BTREE index on users.telegram_booking_user_id
 * with a UNIQUE index, so that a single Telegram user ID cannot be
 * mapped to two different User rows at the DB layer. Defense-in-depth:
 * StaffAuthorizationService::linkPhoneNumber already updates an existing
 * row in-place, but there is no guarantee against a future bug or
 * manual admin SQL mistake causing a collision.
 *
 * Safe to run — verified 2026-04-22 on production that no duplicate
 * telegram_booking_user_id values exist.
 *
 * See docs/audits/2026-04-22-booking-bot-auth.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('idx_telegram_booking_user_id');
            $table->unique('telegram_booking_user_id', 'uniq_telegram_booking_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('uniq_telegram_booking_user_id');
            $table->index('telegram_booking_user_id', 'idx_telegram_booking_user_id');
        });
    }
};
