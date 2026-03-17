<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incoming_webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('source', 50)->index();            // 'beds24', 'gyg', 'telegram'
            $table->string('event_id', 100)->nullable();      // external ID for dedup
            $table->json('payload');                           // raw payload
            $table->enum('status', ['pending', 'processing', 'processed', 'failed'])->default('pending');
            $table->string('error', 500)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->index(['source', 'status']);
            $table->index('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incoming_webhooks');
    }
};
