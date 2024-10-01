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
        Schema::create('booking_tours', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('tour_id')->constrained('tours')->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
            $table->foreignId('guide_id')->constrained('guides')->cascadeOnDelete();
            $table->integer('sub_total');
            $table->string('pickup_location');
            $table->string('dropoff_location');
            $table->date('tour_start_date_time');
            $table->text('special_request');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_tours');
    }
};
