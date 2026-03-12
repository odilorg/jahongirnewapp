<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lost_found_items', function (Blueprint $table) {
            $table->id();
            $table->integer('room_number');
            $table->foreignId('found_by')->constrained('users');
            $table->string('photo_path')->nullable();
            $table->string('telegram_file_id')->nullable();
            $table->string('description');
            $table->enum('status', ['found', 'claimed', 'disposed'])->default('found');
            $table->string('claimed_by_guest')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lost_found_items');
    }
};
