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
        Schema::create('shift_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_drawer_id')->constrained()->onDelete('cascade');
            $table->string('currency', 3);
            $table->decimal('amount', 12, 2)->default(0);
            $table->foreignId('last_shift_id')->nullable()->constrained('cashier_shifts')->onDelete('set null');
            $table->boolean('has_discrepancy')->default(false);
            $table->timestamps();
            
            // Unique constraint: one template per drawer per currency
            $table->unique(['cash_drawer_id', 'currency']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shift_templates');
    }
};