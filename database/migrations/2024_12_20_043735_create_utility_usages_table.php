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
        Schema::create('utility_usages', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->foreignId('utility_id');
            $table->foreignId('meter_id');
            $table->foreignId('hotel_id');
            $table->date('usage_date');
            $table->integer('meter_latest');
            $table->integer('meter_previous');
            $table->integer('meter_difference');
            $table->string('meter_image')->nullable();


        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('utility_usages');
    }
};
