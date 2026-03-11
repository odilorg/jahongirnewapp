<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Drop old table
        Schema::dropIfExists('room_statuses');

        // Simple room status tracking
        Schema::create('room_statuses', function (Blueprint $table) {
            $table->id();
            $table->integer('room_number')->unique(); // 1-15
            $table->enum('status', ['clean', 'dirty'])->default('dirty');
            $table->unsignedBigInteger('cleaned_by')->nullable();
            $table->timestamp('cleaned_at')->nullable();
            $table->timestamps();
            $table->foreign('cleaned_by')->references('id')->on('users')->nullOnDelete();
        });

        // Cleaning history log
        Schema::create('room_cleanings', function (Blueprint $table) {
            $table->id();
            $table->integer('room_number');
            $table->unsignedBigInteger('cleaned_by')->nullable();
            $table->timestamp('cleaned_at');
            $table->timestamps();
            $table->foreign('cleaned_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['room_number', 'cleaned_at']);
        });

        // Issue tracking with photos
        Schema::create('room_issues', function (Blueprint $table) {
            $table->id();
            $table->integer('room_number');
            $table->unsignedBigInteger('reported_by')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('telegram_file_id')->nullable();
            $table->text('description')->nullable();
            $table->enum('priority', ['low', 'medium', 'urgent'])->default('medium');
            $table->enum('status', ['open', 'in_progress', 'resolved'])->default('open');
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();
            $table->foreign('reported_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('resolved_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['room_number', 'status']);
        });

        // Seed rooms 1-15 as dirty
        for ($i = 1; $i <= 15; $i++) {
            DB::table('room_statuses')->insert([
                'room_number' => $i,
                'status'      => 'dirty',
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('room_issues');
        Schema::dropIfExists('room_cleanings');
        Schema::dropIfExists('room_statuses');
    }
};
