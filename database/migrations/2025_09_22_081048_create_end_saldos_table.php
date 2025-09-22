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
        Schema::create('end_saldos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cashier_shift_id')->constrained()->onDelete('cascade');
            $table->string('currency', 3); // UZS, USD, EUR, RUB
            $table->decimal('expected_end_saldo', 12, 2)->default(0);
            $table->decimal('counted_end_saldo', 12, 2)->nullable();
            $table->decimal('discrepancy', 12, 2)->nullable();
            $table->text('discrepancy_reason')->nullable();
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
        Schema::dropIfExists('end_saldos');
    }
};