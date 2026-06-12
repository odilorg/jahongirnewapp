<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 29 — guest experience messaging engine.
 *
 * One pre-materialized row per (booking, message_type). The
 * UNIQUE(booking_inquiry_id, message_type) constraint is the core
 * idempotency spine: a touchpoint exists at most once per booking, so a
 * 5-minute cron can run safely with no risk of duplicate sends.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('guest_experience_messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('booking_inquiry_id')
                ->constrained('booking_inquiries')
                ->cascadeOnDelete();

            // Catalog key, e.g. 'post_pickup_welcome'.
            $table->string('message_type', 40);

            // Resolved at send time: 'whatsapp' (v1 is WhatsApp-only). Null
            // until sent; the column exists so email can be added later
            // without a migration.
            $table->string('channel', 16)->nullable();

            // pending → sending → sent | failed | unknown | skipped | suppressed
            $table->string('status', 16)->default('pending');

            // The cron's sole time filter (stored UTC, computed in Tashkent).
            $table->dateTime('due_at');
            $table->dateTime('sent_at')->nullable();

            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->dateTime('last_attempted_at')->nullable();
            $table->text('last_error')->nullable();

            // Deterministic, stamped before send for crash safety.
            $table->string('idempotency_key')->nullable();

            // Reserved for inbound-reply capture (NOT written in v1).
            $table->boolean('reply_received')->default(false);

            // Optional content/debug snapshot.
            $table->json('meta')->nullable();

            $table->timestamps();

            // Core idempotency: at most one row per touchpoint per booking.
            $table->unique(['booking_inquiry_id', 'message_type'], 'gem_booking_type_unique');

            // Drives the cron scan.
            $table->index(['status', 'due_at'], 'gem_status_due');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_experience_messages');
    }
};
