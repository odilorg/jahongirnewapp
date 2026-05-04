<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Viator V1 — immutable event log for inbound Viator emails.
 *
 * Doctrine: Viator pushes a STREAM of events for the same booking
 * reference (new → amended → amended → cancelled). The booking
 * inquiry is the business object; this table is the append-only event
 * log behind it. Idempotency is on `gmail_message_id` (the IMAP
 * Message-ID header), so the fetcher can re-run safely.
 *
 * `external_reference` is NOT unique here — same BR-XXX may legitimately
 * appear across multiple rows (one per event). Combined with
 * `email_type` it forms a logical grouping the review-queue uses to
 * surface change history per booking.
 *
 * `parsed_payload` holds the full Field: Value extraction.
 * `parsed_diff`    holds, for amendments only, a {field: {old, new}}
 * map computed against the existing BookingInquiry — operators see
 * exactly what changed without diffing JSON manually.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('viator_inbound_emails', function (Blueprint $table) {
            $table->id();

            // Idempotency key — IMAP Message-ID header. Unique so the
            // fetcher can blindly re-process an inbox without duplication.
            $table->string('gmail_message_id', 255)->unique();

            // Originating address — kept for audit + future routing rules
            // (e.g. distinguishing finance@viator.com from booking@t1.viator.com).
            $table->string('from_address', 255);
            $table->string('subject_raw', 500);

            // Detected event type. 'unknown' lets the fetcher store an
            // email it can't classify so an operator can investigate
            // without losing the message.
            $table->enum('email_type', ['new', 'amended', 'cancelled', 'unknown'])
                ->default('unknown');

            // Viator booking reference (BR-XXXXXXXXXX). Extracted from
            // subject for new + amended; from body for cancellations.
            // Indexed but NOT unique — multiple events per booking.
            $table->string('external_reference', 32)->nullable()->index();

            // Full body as fetched (after himalaya MIME unwrap) so we
            // never lose the source of truth even when parsing changes.
            $table->longText('raw_body');

            $table->json('parsed_payload')->nullable();
            $table->json('parsed_diff')->nullable();

            // Lifecycle:
            //   fetched      — saved, not yet parsed
            //   parsed       — parser succeeded, awaiting apply/review
            //   applied      — auto-applied to a BookingInquiry (new only)
            //   needs_review — flagged for operator (amended/cancelled, or
            //                  unrecognised new with no catalog match)
            //   failed       — parser threw / fetcher hit an error
            $table->enum('processing_status', [
                'fetched', 'parsed', 'applied', 'needs_review', 'failed',
            ])->default('fetched');

            $table->timestamp('processed_at')->nullable();
            $table->text('error_message')->nullable();

            // FK once an apply succeeds. Nullable because not every event
            // produces a new inquiry (cancellations target an existing
            // one; failed/needs_review rows have no link until reviewed).
            $table->foreignId('booking_inquiry_id')->nullable()
                ->constrained('booking_inquiries')->nullOnDelete();

            $table->timestamps();

            $table->index(['processing_status', 'created_at']);
            $table->index(['email_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('viator_inbound_emails');
    }
};
