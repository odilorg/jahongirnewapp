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
        Schema::create('telegram_pos_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('cascade');
            $table->bigInteger('telegram_user_id')->nullable();
            $table->string('action'); // auth_started, auth_success, shift_opened, etc.
            $table->text('details')->nullable();
            $table->string('ip_address')->nullable();
            $table->timestamps();
            
            // Indexes for performance
            $table->index(['user_id', 'action']);
            $table->index('telegram_user_id');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram_pos_activities');
    }
};
