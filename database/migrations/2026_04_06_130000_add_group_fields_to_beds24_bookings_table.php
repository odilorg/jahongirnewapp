<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — Group booking schema.
 *
 * Adds two columns to beds24_bookings:
 *   master_booking_id  — Beds24 ID of the group master (null for standalone bookings)
 *   booking_group_size — Total number of rooms in the group (null for standalone)
 *
 * Together these let us:
 *   (a) link sibling room-bookings (same master = same group)
 *   (b) detect incomplete local sync without re-parsing raw JSON every time
 *
 * Backfill reads beds24_raw_data->booking->bookingGroup from existing rows.
 * MySQL JSON_EXTRACT is used for efficiency; rows without bookingGroup or with
 * a single-entry group are left with null (treated as standalone).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beds24_bookings', function (Blueprint $table) {
            // String — Beds24 booking IDs are numeric strings but we store all IDs as strings
            $table->string('master_booking_id', 30)
                ->nullable()
                ->after('beds24_booking_id')
                ->comment('Beds24 group master booking ID; null for standalone bookings');

            $table->unsignedSmallInteger('booking_group_size')
                ->nullable()
                ->after('master_booking_id')
                ->comment('Total rooms in group from Beds24 bookingGroup.ids; null for standalone');

            $table->index('master_booking_id', 'idx_beds24_bookings_master_booking_id');
        });

        // Backfill from stored raw payload.
        // Only MySQL/MariaDB is supported here (project uses MySQL).
        // We only set master_booking_id when:
        //   (a) bookingGroup key exists
        //   (b) bookingGroup.master is non-zero (Beds24 uses 0 for standalone)
        //   (c) group has at least 2 members (single-entry groups are not real groups)
        DB::statement("
            UPDATE beds24_bookings
            SET
                master_booking_id = CAST(
                    JSON_UNQUOTE(
                        JSON_EXTRACT(beds24_raw_data, '$.booking.bookingGroup.master')
                    ) AS CHAR(30)
                ),
                booking_group_size = JSON_LENGTH(
                    JSON_EXTRACT(beds24_raw_data, '$.booking.bookingGroup.ids')
                )
            WHERE
                beds24_raw_data IS NOT NULL
                AND JSON_EXTRACT(beds24_raw_data, '$.booking.bookingGroup') IS NOT NULL
                AND JSON_EXTRACT(beds24_raw_data, '$.booking.bookingGroup.master') IS NOT NULL
                AND CAST(JSON_UNQUOTE(JSON_EXTRACT(beds24_raw_data, '$.booking.bookingGroup.master')) AS UNSIGNED) > 0
                AND JSON_LENGTH(JSON_EXTRACT(beds24_raw_data, '$.booking.bookingGroup.ids')) >= 2
        ");

        // Log how many rows were backfilled
        $count = DB::table('beds24_bookings')->whereNotNull('master_booking_id')->count();
        \Illuminate\Support\Facades\Log::info(
            "Migration add_group_fields_to_beds24_bookings: backfilled {$count} grouped bookings"
        );
    }

    public function down(): void
    {
        Schema::table('beds24_bookings', function (Blueprint $table) {
            $table->dropIndex('idx_beds24_bookings_master_booking_id');
            $table->dropColumn(['master_booking_id', 'booking_group_size']);
        });
    }
};
