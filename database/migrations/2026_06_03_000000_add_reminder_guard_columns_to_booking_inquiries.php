<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_inquiries', function (Blueprint $table) {
            $table->timestamp('guest_reminder_last_attempted_at')->nullable()->after('guest_reminder_sent_at');
            $table->unsignedInteger('guest_reminder_attempt_count')->default(0)->after('guest_reminder_last_attempted_at');
            $table->string('guest_reminder_status')->nullable()->after('guest_reminder_attempt_count');
            $table->string('guest_reminder_idempotency_key')->nullable()->after('guest_reminder_status');
            $table->text('guest_reminder_last_error')->nullable()->after('guest_reminder_idempotency_key');

            // Indexes for operational queries
            $table->index('guest_reminder_sent_at', 'bi_reminder_sent_at_idx');
            $table->index('guest_reminder_last_attempted_at', 'bi_reminder_attempted_idx');
            $table->index('guest_reminder_status', 'bi_reminder_status_idx');

            // Combined index for the hourly cron candidate query
            $table->index(
                ['travel_date', 'guest_reminder_status', 'guest_reminder_sent_at'],
                'bi_reminder_guard_idx'
            );
        });

        // Drop the old composite index (replaced by bi_reminder_guard_idx above).
        // Safe to drop — the new index covers the same leading column (travel_date)
        // plus the two guard columns, so any query the old index served is now
        // served by the new one.
        Schema::table('booking_inquiries', function (Blueprint $table) {
            $table->dropIndex('bi_reminder_window_idx');
        });
    }

    public function down(): void
    {
        Schema::table('booking_inquiries', function (Blueprint $table) {
            $table->dropIndex('bi_reminder_guard_idx');
            $table->dropIndex('bi_reminder_status_idx');
            $table->dropIndex('bi_reminder_attempted_idx');
            $table->dropIndex('bi_reminder_sent_at_idx');

            $table->dropColumn([
                'guest_reminder_last_error',
                'guest_reminder_idempotency_key',
                'guest_reminder_status',
                'guest_reminder_attempt_count',
                'guest_reminder_last_attempted_at',
            ]);

            // Restore old index
            $table->index(['travel_date', 'guest_reminder_sent_at'], 'bi_reminder_window_idx');
        });
    }
};
