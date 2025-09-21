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
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->string('from_currency', 3); // Base currency (UZS)
            $table->string('to_currency', 3);   // Target currency (EUR, USD, RUB)
            $table->decimal('rate', 12, 6);     // Exchange rate
            $table->timestamp('effective_date'); // When this rate becomes effective
            $table->timestamp('expires_at')->nullable(); // When this rate expires
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            // Indexes
            $table->index(['from_currency', 'to_currency', 'effective_date']);
            $table->index(['is_active', 'effective_date']);
            
            // Unique constraint to prevent duplicate rates for same currency pair and date
            $table->unique(['from_currency', 'to_currency', 'effective_date'], 'unique_rate_per_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};