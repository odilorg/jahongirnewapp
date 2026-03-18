<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_bots', function (Blueprint $table) {
            $table->id();

            $table->string('slug')->unique();
            $table->string('name');
            $table->string('bot_username')->nullable();
            $table->text('description')->nullable();

            $table->string('status')->default('active');
            $table->string('environment')->default('production');

            $table->json('metadata')->nullable();

            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->text('last_error_summary')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('environment');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_bots');
    }
};
