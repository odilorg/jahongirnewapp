<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make bookings.pickup_location nullable.
 *
 * Why: GYG private-tour bookings no longer receive a hardcoded meeting point.
 * The column stays null until the guest supplies their hotel name in reply to
 * the post-booking pickup request. TourSendReminders already handles null by
 * falling back to "your hotel" in the WA message.
 *
 * This is a non-destructive ALTER — no rows are dropped or data is lost.
 * Existing group-tour rows retain their "Gur Emir Mausoleum" value unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('pickup_location')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Restore NOT NULL. Note: if any rows have null pickup_location when
        // rolling back, MySQL will refuse unless a default is supplied.
        // Only roll back this migration after clearing null values manually.
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('pickup_location')->nullable(false)->default('')->change();
        });
    }
};
