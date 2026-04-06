<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_ops_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('booking_number', 30);
            $table->string('actor', 64)->comment('Telegram chat_id of the person who performed the action');
            $table->string('action', 50)->comment('confirm|cancel|assign_driver|assign_guide|set_price|set_pickup');
            $table->json('changes')->nullable()->comment('{"field":{"old":X,"new":Y}}');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_ops_logs');
    }
};
