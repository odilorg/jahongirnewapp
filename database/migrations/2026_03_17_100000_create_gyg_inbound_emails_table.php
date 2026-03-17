<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gyg_inbound_emails', function (Blueprint $table) {
            $table->id();

            // Idempotency key — RFC 2822 Message-ID header
            $table->string('email_message_id', 512)->unique();

            // Email envelope
            $table->string('email_from', 255);
            $table->string('email_to', 255)->nullable();
            $table->string('email_subject', 1000);
            $table->timestamp('email_date')->nullable();

            // Bodies
            $table->longText('body_text')->nullable();
            $table->longText('body_html')->nullable();

            // Classification (populated in Phase 4)
            $table->enum('email_type', [
                'new_booking',
                'cancellation',
                'amendment',
                'guest_reply',
                'unknown',
            ])->nullable();

            // Extracted fields (populated in Phase 4+)
            $table->string('gyg_booking_reference', 50)->nullable()->index();
            $table->string('tour_name', 500)->nullable();
            $table->string('guest_name', 255)->nullable();
            $table->string('guest_email', 255)->nullable();
            $table->string('guest_phone', 50)->nullable();
            $table->date('tour_date')->nullable();
            $table->time('tour_time')->nullable();
            $table->unsignedInteger('number_of_guests')->nullable();
            $table->decimal('total_price', 10, 2)->nullable();
            $table->string('currency', 10)->nullable();
            $table->string('language', 20)->nullable();

            // PM-required provenance fields (populated in Phase 4)
            $table->enum('tour_type', ['group', 'private'])->nullable();
            $table->enum('tour_type_source', ['explicit', 'defaulted'])->nullable();
            $table->enum('guide_status', ['with_guide', 'no_guide'])->nullable();
            $table->enum('guide_status_source', ['explicit', 'defaulted'])->nullable();

            // Processing state
            $table->enum('processing_status', [
                'fetched',
                'classified',
                'parsed',
                'applied',
                'needs_review',
                'skipped',
                'failed',
            ])->default('fetched')->index();

            $table->text('parse_error')->nullable();
            $table->text('apply_error')->nullable();
            $table->unsignedInteger('parse_attempts')->default(0);
            $table->timestamp('classified_at')->nullable();
            $table->timestamp('parsed_at')->nullable();
            $table->timestamp('applied_at')->nullable();

            // Link to domain tables (populated in Phase 5)
            $table->unsignedBigInteger('booking_id')->nullable()->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gyg_inbound_emails');
    }
};
