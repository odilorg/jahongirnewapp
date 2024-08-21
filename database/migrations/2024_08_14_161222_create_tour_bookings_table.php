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
        Schema::create('tour_bookings', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->foreignId('tour_id');
            $table->foreignId('guest_id');
            $table->foreignId('driver_id');
            $table->foreignId('guide_id');
            $table->integer('number_of_adults');
            $table->integer('number_of_children');
            $table->text('special_requests');
            $table->string('pickup_location');
            $table->string('dropoff_location');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tour_bookings');
    }
};
