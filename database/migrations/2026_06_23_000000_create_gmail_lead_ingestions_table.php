<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only ledger for the Gmail -> CRM lead ingestion pipeline. One row per
 * inspected message (created OR skipped), keyed by the IMAP Message-ID so the
 * fetcher is idempotent: a re-run skips anything already recorded. Additive,
 * isolated table — does not touch booking_inquiries schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gmail_lead_ingestions', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32)->default('gmail');

            // Idempotency key — IMAP Message-ID (fallback sha256:hash). Unique so
            // re-processing the label is a safe no-op.
            $table->string('gmail_message_id', 255)->unique();
            $table->string('envelope_id', 64)->nullable();

            $table->string('kind', 32)->nullable();   // contact_form | free_form | null
            $table->string('status', 48);             // created | skipped_* | failed

            $table->foreignId('booking_inquiry_id')->nullable()
                ->constrained('booking_inquiries')->nullOnDelete();

            $table->string('sender_email', 255)->nullable();  // the notifier / direct sender
            $table->string('guest_email', 255)->nullable();   // extracted guest address
            $table->string('subject', 500)->nullable();
            $table->boolean('has_attachments')->default(false);
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gmail_lead_ingestions');
    }
};
