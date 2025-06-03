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
        Schema::create('tour_expenses', function (Blueprint $table) {
             $table->id();
    $table->foreignId('tour_id')->constrained()->cascadeOnDelete();
    $table->morphs('supplier'); // For polymorphic relation (driver, guide, etc.)
    $table->string('description')->nullable();
    $table->decimal('amount', 10, 2);
    $table->date('expense_date')->nullable();
    $table->timestamps();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tour_expenses');
    }
};
