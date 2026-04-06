<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make booking_id nullable on drivers and guides.
 *
 * The column was originally NOT NULL, tying each driver/guide to exactly one
 * booking. Drivers and guides are now independent resources managed via the
 * staff panel and may be assigned to many bookings. Nullable removes the
 * legacy constraint without touching existing rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->unsignedBigInteger('booking_id')->nullable()->change();
        });

        Schema::table('guides', function (Blueprint $table) {
            $table->unsignedBigInteger('booking_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Reversing to NOT NULL would fail if any rows have booking_id = null.
        // Left as a no-op — the nullable state is forward-only.
    }
};
