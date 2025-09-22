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
        Schema::create('beginning_saldos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cashier_shift_id')->constrained()->onDelete('cascade');
            $table->string('currency', 3); // UZS, USD, EUR, RUB
            $table->decimal('amount', 12, 2)->default(0);
            $table->timestamps();
            
            // Ensure one record per shift-currency combination
            $table->unique(['cashier_shift_id', 'currency']);
            
            // Indexes for performance
            $table->index(['cashier_shift_id', 'currency']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('beginning_saldos');
    }
};