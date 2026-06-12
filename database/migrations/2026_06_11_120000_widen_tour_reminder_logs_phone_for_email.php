<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen tour_reminder_logs.phone from string(30) to string(190).
 *
 * The guest-reminder audit row stores the recipient in `phone`. With the
 * email-fallback channel (OTA bookings have no phone but a ~50-char GYG/
 * Viator relay email), a 30-char column truncates the address. Widening
 * lets the same column hold either a phone or an email for the `channel`
 * = 'email' rows. No consumer filters on `phone` (only channel /
 * scheduled_for_date / guest_id), so widening is non-destructive.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tour_reminder_logs', function (Blueprint $table) {
            $table->string('phone', 190)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Reversible. Safe only if no stored value exceeds 30 chars; email
        // rows would truncate on rollback, so prefer leaving the column
        // widened. Provided for completeness.
        Schema::table('tour_reminder_logs', function (Blueprint $table) {
            $table->string('phone', 30)->nullable()->change();
        });
    }
};
