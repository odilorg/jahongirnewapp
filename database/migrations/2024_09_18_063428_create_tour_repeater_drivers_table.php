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
        Schema::create('tour_repeater_drivers', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->foreignId('driver_id');
            $table->integer('amount_paid')->nullable();
            $table->foreignId('sold_tour_id');
            $table->date('payment_date')->nullable();
            $table->string('payment_document_image')->nullable();
            $table->string('payment_method')->nullable();


        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tour_repeater_drivers');
    }
};
