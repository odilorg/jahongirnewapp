<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add two more operational fields the driver-dispatch template needs:
 *  - customer_country: nationality to include in the dispatch greeting
 *    ("Mehmon: Noémie Vigne (Fransiya)")
 *  - dropoff_point: destination handoff ("Tushirish joyi: Bukhara")
 *
 * Both nullable — website form doesn't collect them today, operators
 * fill them in as they prepare the tour.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('booking_inquiries', function (Blueprint $table) {
            $table->string('customer_country', 100)->nullable()->after('customer_phone');
            $table->string('dropoff_point', 255)->nullable()->after('pickup_point');
        });
    }

    public function down(): void
    {
        Schema::table('booking_inquiries', function (Blueprint $table) {
            $table->dropColumn(['customer_country', 'dropoff_point']);
        });
    }
};
