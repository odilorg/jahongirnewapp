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
        Schema::create('room_repairs', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->date('repair_date');
            $table->foreignId('hotel_id');
            $table->integer('room_number');
            $table->string('repair_name');
            $table->integer('amount');
            $table->text('notes')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('room_repairs');
    }
};
