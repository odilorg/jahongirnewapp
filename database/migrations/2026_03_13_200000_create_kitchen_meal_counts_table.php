<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kitchen_meal_counts', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('meal_type', 20)->default('breakfast'); // breakfast, lunch, dinner
            $table->unsignedInteger('total_expected')->default(0);
            $table->unsignedInteger('total_adults')->default(0);
            $table->unsignedInteger('total_children')->default(0);
            $table->unsignedInteger('served_count')->default(0);
            $table->timestamps();

            $table->unique(['date', 'meal_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_meal_counts');
    }
};
