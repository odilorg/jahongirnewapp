<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BookingInquiry;
use App\Models\TourFeedback;
use App\Services\Feedback\FeedbackMessageBuilder;
use App\Services\Messaging\WhatsAppSender;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Send post-tour internal feedback requests to guests whose tour ended
 * yesterday.
 *
 * Phase 9.2: now points to the internal feedback flow (token-gated form
 * on jahongir-app.uz) instead of dropping Google/TripAdvisor URLs into
 * the outbound message. Public review CTAs only appear post-positive
 * submission inside the feedback form's thank-you page.
 *
 * Channels: WhatsApp first (wa-api via tunnel), email fallback via
 * himalaya for guests whose number doesn't normalise.
 *
 * Idempotency:
 *   - Eligibility filter excludes inquiries that already have
 *     feedback_request_sent_at set
 *   - We create the TourFeedback row BEFORE the send so a partial
 *     send-then-crash doesn't leave the operator wondering whether
 *     it went out
 */
class TourSendReviewRequests extends Command
{
    protected $signature   = 'tour:send-review-requests {--dry-run : Print without sending}';
    protected $description = 'Send post-tour feedback requests to guests whose tour ended yesterday';

    public function __construct(
        private WhatsAppSender $whatsApp,
        private FeedbackMessageBuilder $messageBuilder,
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

            $sentThis = $this->processInquiry($inquiry);

            if ($sentThis) {
                $inquiry->forceFill(['feedback_request_sent_at' => now()])->save();
                $sent++;
                $this->info('     ✅ Sent');
            } else {
                $failed++;
                $this->warn('     ⚠ No channel available');
            }
        }

        $this->info("Feedback requests done. Sent: {$sent}, Failed: {$failed}");

        return self::SUCCESS;
    }

    private function processInquiry(BookingInquiry $inquiry): bool
    {
        // Pre-create the feedback row with token + supplier snapshot. If
        // the send fails on every channel, we'll delete it back out so the
        // operator can retry tomorrow without orphans.
        $feedback = TourFeedback::create([
            'inquiry_id'       => $inquiry->id,
            'driver_id'        => $inquiry->driver_id,
            'guide_id'         => $inquiry->guide_id,
            'accommodation_id' => $inquiry->stays->first()?->accommodation_id,
            'token'            => TourFeedback::generateToken(),
            'source'           => 'whatsapp',
        ]);

        $url     = url('/feedback/' . $feedback->token);
        $built   = $this->messageBuilder->build($inquiry, $url);
        $message = $built['text'];

        $sentVia = null;
        $phone   = $this->whatsApp->normalizePhone($inquiry->customer_phone);

        if ($phone) {
            $this->line("     → WhatsApp: {$phone}");
            $result = $this->whatsApp->send($phone, $message);
            if ($result->success) {
                $sentVia = 'whatsapp';
                Log::info('TourSendReviewRequests: WhatsApp sent', [
                    'inquiry_id'   => $inquiry->id,
                    'reference'    => $inquiry->reference,
                    'feedback_id'  => $feedback->id,
                    'opener_index' => $built['opener_index'],
                ]);
            } else {
                Log::warning('TourSendReviewRequests: WhatsApp failed', [
                    'inquiry_id' => $inquiry->id,
                    'phone'      => $phone,
                    'error'      => $result->error,
                ]);
            }
        }

        if ($sentVia === null && filled($inquiry->customer_email)) {
            if ($this->sendEmail($inquiry, $message)) {
                $sentVia = 'email';
            }
        }

        if ($sentVia === null) {
            // No channel succeeded — clean up the orphan so retry tomorrow
            // generates a fresh token + opener.
            $feedback->delete();
            return false;
        }

        $feedback->forceFill([
            'source'       => $sentVia,
            'opener_index' => $built['opener_index'],
        ])->save();

        return true;
    }

    private function sendEmail(BookingInquiry $inquiry, string $body): bool
    {
        $email   = trim((string) $inquiry->customer_email);
        $subject = 'How was your trip? · Jahongir Travel';
        $this->line("     → Email: {$email}");

        $mml     = "From: odilorg@gmail.com\nTo: {$email}\nSubject: {$subject}\n\n{$body}";
        $tmpFile = tempnam(sys_get_temp_dir(), 'fb_') . '.eml';
        file_put_contents($tmpFile, $mml);

        $out = [];
        exec('himalaya template send < ' . escapeshellarg($tmpFile) . ' 2>&1', $out, $code);
        @unlink($tmpFile);

        if ($code === 0) {
            Log::info('TourSendReviewRequests: email sent', [
                'inquiry_id' => $inquiry->id,
                'email'      => $email,
            ]);
            return true;
        }

        Log::warning('TourSendReviewRequests: email failed', [
            'inquiry_id' => $inquiry->id,
            'email'      => $email,
            'output'     => implode("\n", $out),
        ]);
        return false;
    }
}
