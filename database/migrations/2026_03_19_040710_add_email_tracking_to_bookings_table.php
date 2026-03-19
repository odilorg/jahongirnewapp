<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('confirmation_sent_at')->nullable()->after('hotel_request_sent_at');
            $table->timestamp('route_request_sent_at')->nullable()->after('confirmation_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['confirmation_sent_at', 'route_request_sent_at']);
        });
    }
};
