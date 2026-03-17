<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency table for Telegram callback_query processing.
 *
 * Lifecycle: processing → succeeded | failed
 * - 'processing': callback claimed, financial operation in progress
 * - 'succeeded': operation completed — permanently blocks retries
 * - 'failed': operation failed — allows retry (row deleted on re-claim)
 *
 * UNIQUE on callback_query_id prevents concurrent claims.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_processed_callbacks', function (Blueprint $table) {
            $table->id();
            $table->string('callback_query_id', 64)->unique();
            $table->bigInteger('chat_id')->nullable();
            $table->string('action', 100)->nullable();
            $table->enum('status', ['processing', 'succeeded', 'failed'])->default('processing');
            $table->string('error', 500)->nullable();
            $table->timestamp('claimed_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_processed_callbacks');
    }
};
