<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_bot_access_logs', function (Blueprint $table) {
            $table->id();

            // Nullable: log entry may reference a bot that was since deleted,
            // or record a failed lookup where no bot was found.
            $table->foreignId('telegram_bot_id')
                ->nullable()
                ->constrained('telegram_bots')
                ->nullOnDelete();

            // Who performed the action (Filament user, system, CLI, etc.)
            $table->foreignId('actor_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('actor_type')->nullable();         // 'user', 'system', 'cli', 'job', 'webhook'
            $table->string('actor_identifier')->nullable();   // class name, command signature, job class

            // What happened
            $table->string('service_name')->nullable();       // FQCN of the caller
            $table->string('action');                         // AccessAction enum value
            $table->string('result');                         // AccessResult enum value

            // Request context (nullable — not all actions have HTTP context)
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->string('request_id')->nullable();

            // Flexible extra data (error codes, bot slugs for failed lookups, etc.)
            $table->json('metadata')->nullable();

            // Append-only: no updated_at column.
            $table->timestamp('created_at')->useCurrent();

            // Query patterns: "show me all accesses for bot X" and "recent errors"
            $table->index('telegram_bot_id');
            $table->index('action');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_bot_access_logs');
    }
};
