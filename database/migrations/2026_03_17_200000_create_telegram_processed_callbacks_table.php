<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency table for Telegram callback_query processing.
 *
 * Each Telegram callback_query has a globally unique ID. By inserting a row
 * before processing and relying on the UNIQUE constraint, we prevent
 * duplicate financial side effects from repeated button presses or
 * Telegram retries.
 *
 * The table is append-only. Old rows can be pruned after 30+ days if needed.
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
            $table->string('result', 20)->default('processed'); // processed, duplicate, error
            $table->timestamp('processed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_processed_callbacks');
    }
};
