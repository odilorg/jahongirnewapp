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
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->string('number');
            $table->date('invoice_date');
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId(column: 'turfirma_id')->constrained()->cascadeOnDelete();
            $table->foreignId(column: 'zayavka_id')->constrained()->cascadeOnDelete();
           
            
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
