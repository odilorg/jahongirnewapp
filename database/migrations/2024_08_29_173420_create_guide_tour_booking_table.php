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
        Schema::create('guide_tour_booking', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->foreignId('guide_id');
            $table->foreignId('tour_booking_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guide_tour_booking');
    }
};
