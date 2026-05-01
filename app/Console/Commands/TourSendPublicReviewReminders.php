<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BookingInquiry;
use App\Services\Messaging\WhatsAppSender;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Day-6 public review follow-up.
 *
 * Sends a soft "if you enjoyed it, please consider a Google review"
 * nudge SIX days after the tour ended — pairs with the Day-1 internal
 * feedback request (tour:send-review-requests) to balance reputation
 * protection (Day-1) with public review volume (Day-6).
 *
 * Eligibility (every guard matters):
 *   - status = confirmed AND cancelled_at IS NULL  → real, completed tour
 *   - travel_date = today − 6 days                 → settled-back-in window
 *   - review_request_sent_at IS NULL                → never publicly nudged before
 *   - AND ONE OF:
 *       · no feedback row at all                    → silent guest, presumed neutral
 *       · feedback row sent but never submitted     → silent guest, presumed neutral
 *       · feedback submitted, all ratings ≥ 4       → happy guest, double-down on public
 *   - EXCLUDE if feedback was submitted with any ≤ 3 → bad rating, never push public
 *
 * Channels: WhatsApp first, email fallback (himalaya). Identical to
 * the Day-1 transport. Stamps the legacy review_request_sent_at column
 * — we deliberately preserved it as v1.5 reuse, not as a separate flag.
 *
 * Single send only. No Day-12 reminder. Over-messaging is the failure
 * mode this guard rail exists to prevent.
 */
class TourSendPublicReviewReminders extends Command
{
    protected $signature   = 'tour:send-public-review-reminders {--dry-run : Print without sending}';
    protected $description = 'Day-6 public review nudge (Google + TripAdvisor) for happy + silent guests';

    private const GOOGLE_REVIEW_URL      = 'https://g.page/r/CYoiUJW5aowWEAE/review';
    private const TRIPADVISOR_REVIEW_URL = 'https://www.tripadvisor.com/UserReviewEdit-g298068-d17464942-Jahongir_Travel-Samarkand_Samarqand_Province.html';

    public function __construct(
        private WhatsAppSender $whatsApp,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun     = (bool) $this->option('dry-run');
        $tz         = 'Asia/Tashkent';
        $travelDate = Carbon::now($tz)->subDays(6)->toDateString();

        if ($dryRun) {
            $this->info('[DRY-RUN] No messages will be sent.');
        }

        $this->info("Looking for tours that ended on: {$travelDate} (D-6)");

        $candidates = BookingInquiry::query()
            ->where('status', BookingInquiry::STATUS_CONFIRMED)
            ->whereNull('cancelled_at')
            ->whereNull('review_request_sent_at')
            ->where('travel_date', $travelDate)
            ->with('feedback')
            ->get();

        // Daily volume is small (a few rows), so in-memory filter using the
        // model helper is cleaner than a complex SQL where-exists. If volume
        // grows past ~50/day, push this into a query scope.
        $eligible = $candidates->filter(function (BookingInquiry $inquiry) {
            $fb = $inquiry->feedback;

            if ($fb === null) {
                return true; // no feedback row at all — presumed neutral
            }
            if ($fb->submitted_at === null) {
                return true; // sent but unfilled — presumed neutral
            }

            return ! $fb->isLowRated(); // happy if no rating ≤ 3
        });

        if ($eligible->isEmpty()) {
            $this->info('No eligible guests for public review nudge today.');
            return self::SUCCESS;
        }

        $sent = 0;
        $failed = 0;

        foreach ($eligible as $inquiry) {
            $this->info("  📬 Public review nudge to {$inquiry->customer_name} ({$inquiry->reference})");

            if ($dryRun) {
                $sent++;
                continue;
            }

            if ($this->processInquiry($inquiry)) {
                $inquiry->forceFill(['review_request_sent_at' => now()])->save();
                $sent++;
                $this->info('     ✅ Sent');
            } else {
                $failed++;
                $this->warn('     ⚠ No channel available');
            }
        }

        $this->info("Public review nudges done. Sent: {$sent}, Failed: {$failed}");

        return self::SUCCESS;
    }

    private function processInquiry(BookingInquiry $inquiry): bool
    {
        $message = $this->buildMessage($inquiry);
        $sentVia = null;

        $phone = $this->whatsApp->normalizePhone($inquiry->customer_phone);
        if ($phone) {
            $this->line("     → WhatsApp: {$phone}");
            $result = $this->whatsApp->send($phone, $message);
            if ($result->success) {
                $sentVia = 'whatsapp';
                Log::info('TourSendPublicReviewReminders: WhatsApp sent', [
                    'inquiry_id' => $inquiry->id,
                    'reference'  => $inquiry->reference,
                ]);
            } else {
                Log::warning('TourSendPublicReviewReminders: WhatsApp failed', [
                    'inquiry_id' => $inquiry->id,
                    'phone'      => $phone,
                    'error'      => $result->error,
                ]);
            }
        }

        if ($sentVia === null && filled($inquiry->customer_email)) {
            $sentVia = $this->sendEmail($inquiry, $message) ? 'email' : null;
        }

        return $sentVia !== null;
    }

    /**
     * Day-6 message — appreciation tone, distinct from Day-1's check-in.
     * Hard-coded for now (one variant, low volume); promote to a config
     * file the moment we need 2+ tonal variants.
     */
    private function buildMessage(BookingInquiry $inquiry): string
    {
        $first = trim((string) strtok((string) $inquiry->customer_name, ' '));
        $hi    = $first !== '' ? "Hi {$first} 👋" : 'Hi 👋';

        return $hi . " Hope you have been settling back in well after your trip with us.\n\n"
             . "If you enjoyed your time in Uzbekistan, we would genuinely appreciate a quick public review — it really helps future travellers find us 🙏\n\n"
             . '🌟 Google: ' . self::GOOGLE_REVIEW_URL . "\n"
             . '🌟 TripAdvisor: ' . self::TRIPADVISOR_REVIEW_URL . "\n\n"
             . "Even just a few words goes a long way. Thank you!\n"
             . '— Jahongir Travel';
    }

    private function sendEmail(BookingInquiry $inquiry, string $body): bool
    {
        $email   = trim((string) $inquiry->customer_email);
        $subject = 'Would you mind a quick review? · Jahongir Travel';
        $this->line("     → Email: {$email}");

        $mml     = "From: odilorg@gmail.com\nTo: {$email}\nSubject: {$subject}\n\n{$body}";
        $tmpFile = tempnam(sys_get_temp_dir(), 'pubrev_') . '.eml';
        file_put_contents($tmpFile, $mml);

        $out = [];
        exec('himalaya template send < ' . escapeshellarg($tmpFile) . ' 2>&1', $out, $code);
        @unlink($tmpFile);

        if ($code === 0) {
            Log::info('TourSendPublicReviewReminders: email sent', [
                'inquiry_id' => $inquiry->id,
                'email'      => $email,
            ]);
            return true;
        }

        Log::warning('TourSendPublicReviewReminders: email failed', [
            'inquiry_id' => $inquiry->id,
            'email'      => $email,
            'output'     => implode("\n", $out),
        ]);
        return false;
    }
}
