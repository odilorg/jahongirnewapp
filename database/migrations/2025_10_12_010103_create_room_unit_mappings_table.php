<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_unit_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('unit_name', 50)->unique(); // e.g., "12", "22", "101"
            $table->string('property_id', 100);
            $table->string('property_name');
            $table->string('room_id', 100);
            $table->string('room_name');
            $table->string('room_type', 100)->nullable();
            $table->integer('max_guests')->nullable();
            $table->decimal('base_price', 10, 2)->nullable();
            $table->timestamps();
            
            $table->index('unit_name');
            $table->index('property_id');
            $table->index('room_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_unit_mappings');
    }
};
