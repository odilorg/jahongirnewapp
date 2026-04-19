<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lead_interactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('channel', 32);
            $table->string('direction', 16);

            $table->string('subject')->nullable();
            $table->text('body');

            $table->boolean('is_important')->default(false);

            // Reserved for inbound webhook ingestion in v2. Unused in v1.
            $table->json('raw_payload')->nullable();

            $table->timestamp('occurred_at');
            // Append-only: no updated_at. Model sets UPDATED_AT = null.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['lead_id', 'occurred_at']);
            $table->index(['lead_id', 'is_important']);
            $table->index(['channel', 'direction']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_interactions');
    }
};
