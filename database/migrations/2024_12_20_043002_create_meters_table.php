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
        Schema::create('meters', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->string('meter_serial_number');
            $table->foreignId('utility_id');
            $table->date('sertificate_expiration_date');
            $table->string('sertificate_image')->nullable();
            $table->string('contract_number');
            $table->date('contract_date');



        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meters');
    }
};
