<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authorized_staff', function (Blueprint $table) {
            $table->id();
            $table->string('phone_number', 50)->unique();
            $table->bigInteger('telegram_user_id')->unique()->nullable();
            $table->string('telegram_username')->nullable();
            $table->string('full_name');
            $table->enum('role', ['admin', 'receptionist', 'manager'])->default('receptionist');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_active_at')->nullable();
            $table->timestamps();
            
            $table->index('phone_number');
            $table->index('telegram_user_id');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authorized_staff');
    }
};
