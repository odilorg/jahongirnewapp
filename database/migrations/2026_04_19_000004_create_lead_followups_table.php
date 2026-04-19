<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lead_followups', function (Blueprint $table) {
            $table->id();

            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_interest_id')
                ->nullable()
                ->constrained('lead_interests')
                ->nullOnDelete();

            $table->timestamp('due_at');
            $table->timestamp('snoozed_until')->nullable();

            $table->string('type', 16)->default('other');
            $table->text('note')->nullable();

            $table->string('status', 32)->default('open');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['status', 'due_at']);
            $table->index(['status', 'snoozed_until']);
            $table->index(['lead_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_followups');
    }
};
