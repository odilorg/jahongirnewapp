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
        Schema::create('zayavkas', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->foreignId('turfirma_id')->constrained()->cascadeOnDelete();
            $table->date(column: 'submitted_date');
            $table->string('status');
            $table->string('source');            
            $table->string('accepted_by');
            $table->string('description');
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();


        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('zayavkas');
    }
};
