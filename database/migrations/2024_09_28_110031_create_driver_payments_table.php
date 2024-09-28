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
        Schema::create('driver_payments', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->foreignId('driver_id');
            $table->foreignId('sold_tour_id');
            $table->integer('amount_paid');
            $table->date('payment_date');
            $table->string('receipt_image')->nullable();
            $table->string('payment_type');







        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('driver_payments');
    }
};
