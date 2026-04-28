<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 — Direction-level default pickup point (Q3 in PHASE_1 spec).
 *
 * Pickup point is route-level, not departure-level. Yurt Camp departures
 * from Samarkand all start at Gur Emir Mausoleum; this column lets the
 * DepartureResource form auto-fill pickup_point from the selected
 * direction without operators retyping it on every departure.
 *
 * Operators may still override per-departure.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tour_product_directions', function (Blueprint $table) {
            $table->string('default_pickup_point', 255)
                ->nullable()
                ->after('end_city');
        });
    }

    public function down(): void
    {
        Schema::table('tour_product_directions', function (Blueprint $table) {
            $table->dropColumn('default_pickup_point');
        });
    }
};
