<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9.2 — Idempotency timestamps for the follow-up pipeline.
 *
 * These nullable timestamps mirror the columns that were on the legacy
 * `bookings` table. Each follow-up command checks its respective column
 * to prevent double-sends.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_inquiries', function (Blueprint $table) {
            $table->timestamp('review_request_sent_at')->nullable()->after('cancelled_at');
            $table->timestamp('hotel_request_sent_at')->nullable()->after('review_request_sent_at');
        });

        // tour_reminder_logs: add booking_inquiry_id alongside legacy booking_id
        Schema::table('tour_reminder_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('booking_inquiry_id')->nullable()->after('booking_id');
            $table->index(['booking_inquiry_id', 'channel', 'scheduled_for_date'], 'trl_inquiry_channel_date');
        });
    }

    public function down(): void
    {
        Schema::table('booking_inquiries', function (Blueprint $table) {
            $table->dropColumn(['review_request_sent_at', 'hotel_request_sent_at']);
        });

        Schema::table('tour_reminder_logs', function (Blueprint $table) {
            $table->dropIndex('trl_inquiry_channel_date');
            $table->dropColumn('booking_inquiry_id');
        });
    }
};
