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
        Schema::table('sold_tours', function (Blueprint $table) {
            $table->foreignId('tour_id');
            $table->string('pickup_location');
            $table->string('dropoff_location');
            $table->text('special_request')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sold_tours', function (Blueprint $table) {
            //
        });
    }
};
