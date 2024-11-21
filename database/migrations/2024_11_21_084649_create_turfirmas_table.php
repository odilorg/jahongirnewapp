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
        Schema::create('turfirmas', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->string('name');
            $table->string('official_name');
            $table->string('address_street');
            $table->string('address_city');
            $table->string('phone');
            $table->string('email');
            $table->integer('inn');
            $table->integer('account_number');
            $table->string('bank_name');
            $table->integer('bank_mfo');
            

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('turfirmas');
    }
};
