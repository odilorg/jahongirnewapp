<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_inquiries', function (Blueprint $table) {
            $table->timestamp('payment_reminder_sent_at')
                ->nullable()
                ->after('hotel_request_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('booking_inquiries', function (Blueprint $table) {
            $table->dropColumn('payment_reminder_sent_at');
        });
    }
};
