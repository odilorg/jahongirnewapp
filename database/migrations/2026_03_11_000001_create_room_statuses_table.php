<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_statuses', function (Blueprint $table) {
            $table->id();
            $table->integer('beds24_property_id');
            $table->integer('beds24_room_id');
            $table->string('room_name');
            $table->integer('unit_number');
            $table->string('unit_name')->nullable();
            $table->enum('status', ['clean', 'dirty', 'repair'])->default('dirty');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // Named short index to stay within MySQL 64-char limit
            $table->unique(['beds24_property_id', 'beds24_room_id', 'unit_number'], 'rs_prop_room_unit_unique');
            $table->foreign('updated_by', 'rs_updated_by_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_statuses');
    }
};
