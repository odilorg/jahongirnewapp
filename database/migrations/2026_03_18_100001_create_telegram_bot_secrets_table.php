<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_bot_secrets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('telegram_bot_id')
                ->constrained('telegram_bots')
                ->cascadeOnDelete();

            $table->unsignedInteger('version');

            $table->text('token_encrypted');
            $table->text('webhook_secret_encrypted')->nullable();

            $table->string('status')->default('pending');

            $table->timestamp('activated_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['telegram_bot_id', 'version']);
            $table->index(['telegram_bot_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_bot_secrets');
    }
};
