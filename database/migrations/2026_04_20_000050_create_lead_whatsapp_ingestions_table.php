<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lead_whatsapp_ingestions', function (Blueprint $table) {
            $table->id();

            $table->string('provider', 32);   // 'wacli' initially; kept generic for future WA providers

            // wacli message id (globally unique per WhatsApp account). Dedupe key.
            $table->string('remote_message_id', 128);

            // Chat-scoped identifiers for debugging / audit.
            $table->string('chat_jid', 128)->nullable();
            $table->string('sender_jid', 128)->nullable();
            $table->string('chat_name', 255)->nullable();

            $table->foreignId('lead_id')
                ->nullable()
                ->constrained('leads')
                ->nullOnDelete();

            // processed | ambiguous | skipped_duplicate | skipped_self | skipped_group
            // | skipped_no_phone | skipped_blocklist | skipped_outbound | failed
            $table->string('status', 32);

            $table->string('sender_phone', 32)->nullable();
            $table->text('body_preview')->nullable(); // first ~500 chars of message text

            $table->boolean('from_me')->default(false);
            $table->boolean('has_media')->default(false);
            $table->string('media_type', 32)->nullable();

            $table->timestamp('remote_sent_at')->nullable();

            $table->text('error_message')->nullable();
            $table->timestamp('processed_at');

            $table->timestamps();

            $table->unique(['provider', 'remote_message_id']);
            $table->index(['status', 'created_at']);
            $table->index('lead_id');
            $table->index('chat_jid');
            $table->index('remote_sent_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_whatsapp_ingestions');
    }
};
