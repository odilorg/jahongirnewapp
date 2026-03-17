<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->enum('partner_status', ['pending', 'approved', 'rejected'])->nullable()->after('booking_status');
            $table->timestamp('partner_notified_at')->nullable()->after('partner_status');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['partner_status', 'partner_notified_at']);
        });
    }
};
