<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_operators', function (Blueprint $table) {
            $table->id();
            $table->string('telegram_user_id')->unique(); // from.id — stable across chat types
            $table->string('telegram_chat_id')->nullable(); // optional DM chat ID for convenience
            $table->string('role')->default('operator'); // admin|manager|operator|viewer
            $table->boolean('is_active')->default(true);
            $table->string('name')->nullable();     // display name
            $table->string('username')->nullable(); // @handle without the @
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_operators');
    }
};
