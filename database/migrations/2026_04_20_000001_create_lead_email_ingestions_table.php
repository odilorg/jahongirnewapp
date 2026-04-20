<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lead_email_ingestions', function (Blueprint $table) {
            $table->id();

            // Provider kept generic — future Telegram / WhatsApp ingestion reuses this table.
            $table->string('provider', 32);

            // RFC 5322 Message-ID header. Our dedupe key — UNIQUE(provider, remote_message_id) below.
            $table->string('remote_message_id', 255);

            // IMAP UIDs ARE numeric but stored as string because provider clients sometimes shape them differently.
            $table->string('remote_uid', 64)->nullable();
            $table->string('remote_folder', 64)->nullable();

            $table->foreignId('lead_id')
                ->nullable()
                ->constrained('leads')
                ->nullOnDelete();

            // processed | ambiguous | skipped_blocklist | skipped_no_sender | skipped_duplicate | failed
            $table->string('status', 32);

            $table->string('sender_email', 255)->nullable();
            $table->string('subject', 500)->nullable();

            $table->boolean('has_attachments')->default(false);
            $table->json('attachment_filenames')->nullable();

            $table->text('error_message')->nullable();
            $table->timestamp('processed_at');

            $table->timestamps();

            // DB-level dedupe guarantee; survives overlapping cron runs.
            $table->unique(['provider', 'remote_message_id']);
            $table->index(['status', 'created_at']);
            $table->index('lead_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_email_ingestions');
    }
};
