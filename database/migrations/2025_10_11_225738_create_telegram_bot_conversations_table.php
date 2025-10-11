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
        Schema::create('telegram_bot_conversations', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('chat_id')->index();
            $table->bigInteger('message_id');
            $table->bigInteger('user_id')->nullable();
            $table->string('username')->nullable();
            $table->text('message_text');
            $table->date('check_in_date')->nullable();
            $table->date('check_out_date')->nullable();
            $table->json('ai_response')->nullable();
            $table->json('availability_data')->nullable();
            $table->string('status')->default('pending')->index(); // pending, processed, failed
            $table->text('error_message')->nullable();
            $table->decimal('response_time', 8, 2)->nullable()->comment('Response time in seconds');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram_bot_conversations');
    }
};
