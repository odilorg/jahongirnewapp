<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Split guest-context notes into two recipient-specific fields.
 *
 * `operational_notes` (kept) is the DRIVER field — pickup logistics,
 * cross-border handoff, accessibility, language — sent to the assigned
 * driver/guide. This adds `accommodation_notes` for the CAMP/HOTEL —
 * dietary, occasion, room setup — sent to the stay supplier. The four
 * operational flags (♿🍃🗣🎉) derive from the UNION of both fields, so
 * a need recorded in either still surfaces on the calendar.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('booking_inquiries', function (Blueprint $table) {
            $table->text('accommodation_notes')->nullable()->after('operational_notes');
        });
    }

    public function down(): void
    {
        Schema::table('booking_inquiries', function (Blueprint $table) {
            $table->dropColumn('accommodation_notes');
        });
    }
};
