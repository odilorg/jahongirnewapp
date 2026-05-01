<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Post-tour internal feedback collection.
 *
 * Sent ~12–24h after the tour ends via WhatsApp (email fallback). Guests
 * land on a public token-gated page, rate driver / guide / accommodation
 * / overall, optionally pick issue chips for any low rating, optionally
 * leave a comment.
 *
 * Distinct from public-review reminders (Google / TripAdvisor) — those
 * are now triggered ONLY post-positive submission inside the same flow.
 *
 * Supplier ids are snapshotted at the moment the reminder is sent, so
 * later reassignments on the inquiry don't slide a rating onto a
 * different driver/guide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tour_feedbacks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('inquiry_id')
                ->constrained('booking_inquiries')
                ->cascadeOnDelete();

            // Snapshot at send time — set null only if the inquiry truly
            // had no supplier assigned. The form will skip rating rows for
            // null supplier ids, keeping the form honest and short.
            $table->foreignId('driver_id')->nullable()
                ->constrained('drivers')->nullOnDelete();
            $table->foreignId('guide_id')->nullable()
                ->constrained('guides')->nullOnDelete();
            $table->foreignId('accommodation_id')->nullable()
                ->constrained('accommodations')->nullOnDelete();

            $table->unsignedTinyInteger('driver_rating')->nullable();
            $table->unsignedTinyInteger('guide_rating')->nullable();
            $table->unsignedTinyInteger('accommodation_rating')->nullable();
            $table->unsignedTinyInteger('overall_rating')->nullable();

            // Multi-select issue chips when a rating ≤ 3. Stored as JSON of
            // string keys (e.g. ["communication","punctuality"]). Keys live
            // in config/feedback_issue_tags.php so renaming is a config edit,
            // not a migration.
            $table->json('driver_issue_tags')->nullable();
            $table->json('guide_issue_tags')->nullable();
            $table->json('accommodation_issue_tags')->nullable();

            $table->text('comments')->nullable();

            // Random URL-safe token, 32 chars. Single use.
            $table->string('token', 40)->unique();

            $table->enum('source', ['whatsapp', 'email', 'manual'])
                ->default('whatsapp');

            // Index of the opener phrase chosen from config/feedback_openers.php.
            // Audit trail + lets us avoid reusing the immediately-previous
            // phrase for the same guest on a later trip.
            $table->unsignedSmallInteger('opener_index')->nullable();

            // Null until the guest actually submits. We persist sent-but-
            // unfilled rows so we can compute completion rate.
            $table->timestamp('submitted_at')->nullable();

            // Captured on submit, scrubbed by F5 cron after 30 days.
            $table->string('ip_address', 45)->nullable();

            $table->timestamps();

            $table->index(['inquiry_id', 'submitted_at']);
            $table->index(['driver_id', 'submitted_at']);
            $table->index(['guide_id', 'submitted_at']);
            $table->index(['accommodation_id', 'submitted_at']);
            $table->index('overall_rating');
        });

        // Parallel timestamp on the inquiry itself. Distinct from the legacy
        // review_request_sent_at (Google/TripAdvisor) which stays untouched
        // for one release cycle so we can roll back without data loss.
        Schema::table('booking_inquiries', function (Blueprint $table) {
            $table->timestamp('feedback_request_sent_at')
                ->nullable()
                ->after('review_request_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('booking_inquiries', function (Blueprint $table) {
            $table->dropColumn('feedback_request_sent_at');
        });

        Schema::dropIfExists('tour_feedbacks');
    }
};
