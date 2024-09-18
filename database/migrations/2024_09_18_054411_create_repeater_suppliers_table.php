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
        Schema::create('repeater_suppliers', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->foreignId('driver_id');
            $table->foreignId('guide_id');
            $table->integer('amount_paid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('repeater_suppliers');
    }
};
