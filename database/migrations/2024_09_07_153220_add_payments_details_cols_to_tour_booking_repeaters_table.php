<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tour_booking_repeaters', function (Blueprint $table) {
            $table->date('payment_date')->nullable();
            $table->double('amount_paid'); 
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tour_booking_repeaters', function (Blueprint $table) {
            //
        });
    }
};
