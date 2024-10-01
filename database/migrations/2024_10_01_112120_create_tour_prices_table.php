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
        Schema::create('tour_prices', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->foreignId('tour_id');
            $table->integer('number_people');
            $table->integer('tour_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tour_prices');
    }
};
