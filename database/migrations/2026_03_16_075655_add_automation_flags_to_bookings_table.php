<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('review_request_sent_at')->nullable()->after('do_not_remind');
            $table->timestamp('hotel_request_sent_at')->nullable()->after('review_request_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['review_request_sent_at', 'hotel_request_sent_at']);
        });
    }
};
