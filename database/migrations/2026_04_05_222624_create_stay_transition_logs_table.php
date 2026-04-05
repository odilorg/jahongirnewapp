<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stay_transition_logs', function (Blueprint $table) {
            $table->id();
            $table->string('beds24_booking_id', 30)->index();
            $table->unsignedBigInteger('actor_user_id')->index();
            $table->string('action', 20);          // 'check_in' | 'check_out'
            $table->string('old_status', 30);
            $table->string('new_status', 30);
            $table->string('source', 60);          // e.g. 'telegram_cashier_bot'
            $table->timestamp('created_at')->useCurrent();
            // No updated_at — immutable audit log
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stay_transition_logs');
    }
};
