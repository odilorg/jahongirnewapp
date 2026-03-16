<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_availability', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('drivers')->onDelete('cascade');
            $table->date('available_date');
            $table->boolean('is_available')->default(false);
            $table->timestamps();

            $table->unique(['driver_id', 'available_date']);
            $table->index(['driver_id', 'available_date', 'is_available']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_availability');
    }
};
