<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Feedback\SendManualInternalFeedbackRequestAction;
use App\Models\BookingInquiry;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Batch-send post-tour internal feedback requests to guests whose tour
 * ended yesterday.
 *
 * 2026-05-07: this command is **no longer scheduled**. It still works
 * when invoked by hand for batch backfills, but the per-inquiry send
 * logic was lifted into SendManualInternalFeedbackRequestAction so the
 * same path is shared with the operator-driven Filament button (and
 * future surfaces). CLAUDE.md hard line: no duplicated business rule.
 *
 * Phase 9.2: points to the internal feedback flow (token-gated form
 * on jahongir-app.uz) instead of dropping Google/TripAdvisor URLs into
 * the outbound message. Public review CTAs only appear post-positive
 * submission inside the feedback form's thank-you page.
 *
 * Channels: WhatsApp first (wa-api via tunnel), email fallback via
 * himalaya for guests whose number doesn't normalise — both delegated
 * to the Action.
 *
 * Idempotency: eligibility filter excludes inquiries that already have
 * feedback_request_sent_at set. The Action's stamp-on-success-only
 * contract means a transport failure doesn't poison retries.
 */
class TourSendReviewRequests extends Command
{
    protected $signature   = 'tour:send-review-requests {--dry-run : Print without sending}';

    protected $description = 'Send post-tour feedback requests to guests whose tour ended yesterday (manual / backfill use only — not scheduled)';

    public function __construct(
        private SendManualInternalFeedbackRequestAction $sendAction,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun    = (bool) $this->option('dry-run');
        $tz        = 'Asia/Tashkent';
        $yesterday = Carbon::now($tz)->subDay()->toDateString();

        if ($dryRun) {
            $this->info('[DRY-RUN] No messages will be sent.');
        }

        $this->info("Looking for tours that ended on: {$yesterday}");

        // Defense-in-depth eligibility:
        //   status=confirmed already excludes 'cancelled' / 'spam' rows,
        //   but a partial cancellation could leave status=confirmed with
        //   cancelled_at populated (rare but possible during ops handovers).
        //   Belt-and-braces: skip any row with a non-null cancelled_at.
        $inquiries = BookingInquiry::query()
            ->where('status', BookingInquiry::STATUS_CONFIRMED)
            ->whereNull('cancelled_at')
            ->whereNull('feedback_request_sent_at')
            ->where('travel_date', $yesterday)
            ->with(['stays:id,booking_inquiry_id,accommodation_id,sort_order'])
            ->get();

        if ($inquiries->isEmpty()) {
            $this->info('No tours ended yesterday — nothing to send.');

            return self::SUCCESS;
        }

        $sent   = 0;
        $failed = 0;

        foreach ($inquiries as $inquiry) {
            $this->info("  📬 Sending feedback request to {$inquiry->customer_name} ({$inquiry->reference})");

            if ($dryRun) {
                $sent++;

                continue;
            }

            // Single source of truth: same Action used by the Filament
            // operator button. Action handles token creation, WhatsApp,
            // email fallback, orphan cleanup, and stamping
            // feedback_request_sent_at on success.
            $result = $this->sendAction->execute($inquiry);

            if ($result['sent']) {
                $sent++;
                $this->info("     ✅ Sent via {$result['channel']}");
            } else {
                $failed++;
                $this->warn('     ⚠ ' . ($result['reason'] ?? 'No channel available'));
            }
        }

        $this->info("Feedback requests done. Sent: {$sent}, Failed: {$failed}");

        return self::SUCCESS;
    }
}
