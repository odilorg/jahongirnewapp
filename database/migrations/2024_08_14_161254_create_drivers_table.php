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
        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email');            
            $table->string('phone01');
            $table->string('phone02')->nullable();
            $table->string('fuel_type');
            $table->string('driver_image')->nullable();
          //  $table->foreignId('car_id');
            $table->string('full_name')->virtualAs('concat(first_name, \' \', last_name)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('drivers');
    }
};
