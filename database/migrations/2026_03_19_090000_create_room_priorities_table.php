<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_priorities', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('room_number');
            $table->enum('priority', ['urgent', 'important']);
            $table->string('reason', 500)->nullable();
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('priority_date');
            $table->dateTime('expires_at');
            $table->timestamps();

            // One active priority per room per day
            $table->unique(['room_number', 'priority_date']);
            $table->index('expires_at');
            $table->index('priority_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_priorities');
    }
};
