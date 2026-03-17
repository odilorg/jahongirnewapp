<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tours', function (Blueprint $table) {
            // Short arrow route shown at top of driver brief
            // e.g. "Sam → Aydarkul → Obed → Yurta → Nurota → Rabati Malik → Ceramica → Buhoro"
            $table->string('driver_route', 500)->nullable()->after('pickup_time');

            // Full free-text notes for driver: contacts, logistics, drop-off, meals, tips
            $table->text('driver_brief')->nullable()->after('driver_route');
        });
    }

    public function down(): void
    {
        Schema::table('tours', function (Blueprint $table) {
            $table->dropColumn(['driver_route', 'driver_brief']);
        });
    }
};
