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
        Schema::create('cash_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cashier_shift_id')->unique()->constrained()->onDelete('cascade');
            $table->json('denominations'); // Array of {denomination: decimal, qty: int}
            $table->decimal('total', 12, 2);
            $table->text('notes')->nullable();
            $table->timestamps();
            
            // Index for performance
            $table->index('cashier_shift_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_counts');
    }
};