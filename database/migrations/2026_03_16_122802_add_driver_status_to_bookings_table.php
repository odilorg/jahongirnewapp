<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->enum('driver_status', ['pending', 'confirmed', 'rejected'])->nullable()->after('partner_notified_at');
            $table->timestamp('driver_notified_at')->nullable()->after('driver_status');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['driver_status', 'driver_notified_at']);
        });
    }
};
