<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('booking_inquiries', function (Blueprint $table) {
            $table->timestamp('guest_reminder_sent_at')->nullable()->after('payment_reminder_sent_at');
            $table->timestamp('staff_reminder_sent_at')->nullable()->after('guest_reminder_sent_at');
            $table->timestamp('driver_reminder_sent_at')->nullable()->after('staff_reminder_sent_at');

            // Index used by the hourly cron query
            // (whereNull('guest_reminder_sent_at') + travel_date range scan).
            $table->index(['travel_date', 'guest_reminder_sent_at'], 'bi_reminder_window_idx');
        });
    }

    public function down(): void
    {
        Schema::table('booking_inquiries', function (Blueprint $table) {
            $table->dropIndex('bi_reminder_window_idx');
            $table->dropColumn(['guest_reminder_sent_at', 'staff_reminder_sent_at', 'driver_reminder_sent_at']);
        });
    }
};
