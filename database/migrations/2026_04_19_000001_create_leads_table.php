<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();

            $table->string('name')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('whatsapp_number', 32)->nullable();
            $table->string('telegram_username', 64)->nullable();
            $table->string('telegram_chat_id', 64)->nullable();

            $table->string('preferred_channel', 16)->nullable();
            $table->string('source', 32);
            $table->string('language', 8)->nullable();
            $table->string('country')->nullable();

            $table->string('status', 32)->default('new');
            $table->string('priority', 16)->default('medium');
            $table->text('waiting_reason')->nullable();

            $table->foreignId('assigned_to')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Denormalized, maintained by LeadInteractionObserver / LeadFollowUpObserver.
            $table->timestamp('last_interaction_at')->nullable();
            $table->timestamp('next_followup_at')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'next_followup_at']);
            $table->index(['assigned_to', 'status']);
            $table->index('priority');
            $table->index('telegram_chat_id');
            $table->index('whatsapp_number');
            $table->index('phone');
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
