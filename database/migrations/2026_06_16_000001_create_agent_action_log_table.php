<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit + idempotency ledger for tour-agent write actions (agent:apply).
 *
 * Every agent action attempt — applied, simulated, refused or failed — lands
 * here keyed by a deterministic idempotency_key (unique), so a retried
 * approval (e.g. a dropped Telegram callback) can never double-apply, and
 * every agent-originated change is inspectable. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_action_log', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_inquiry_id')->nullable()
                ->constrained('booking_inquiries')->nullOnDelete();
            $table->string('action', 64);
            $table->json('params')->nullable();
            $table->string('actor', 64)->default('tour-agent');
            $table->string('approval_token', 128)->nullable();
            // 191 keeps the unique index within the utf8mb4 key-length limit.
            $table->string('idempotency_key', 191)->unique();
            $table->string('status', 24);          // applied | simulated | refused | failed
            $table->string('reason', 64)->nullable();
            $table->json('result')->nullable();
            $table->text('preview')->nullable();   // rendered guest message (Tier-2 dry preview)
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['booking_inquiry_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_action_log');
    }
};
