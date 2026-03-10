<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->string('beds24_booking_id')->nullable()->after('reference');
            $table->string('payment_method')->nullable()->after('beds24_booking_id'); // cash, card, transfer
            $table->string('guest_name')->nullable()->after('payment_method');
            $table->string('room_number')->nullable()->after('guest_name');

            $table->index('beds24_booking_id');
        });
    }

    public function down(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->dropIndex(['beds24_booking_id']);
            $table->dropColumn(['beds24_booking_id', 'payment_method', 'guest_name', 'room_number']);
        });
    }
};
