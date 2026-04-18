<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BookingInquiry;
use App\Services\Messaging\WhatsAppSender;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Send post-tour review requests to guests whose tour ended yesterday.
 *
 * Repointed from legacy `bookings` to `booking_inquiries` in Phase 9.2.
 * Now uses WhatsAppSender (wa-api via tunnel) instead of wacli CLI.
 * Email fallback via himalaya for guests without WhatsApp.
 */
class TourSendReviewRequests extends Command
{
    protected $signature   = 'tour:send-review-requests {--dry-run : Print without sending}';
    protected $description = 'Send post-tour review requests to guests whose tour ended yesterday';

    private const GOOGLE_REVIEW_URL     = 'https://g.page/r/CYoiUJW5aowWEAE/review';
    private const TRIPADVISOR_REVIEW_URL = 'https://www.tripadvisor.com/UserReviewEdit-g298068-d17464942-Jahongir_Travel-Samarkand_Samarqand_Province.html';

    public function __construct(
        private WhatsAppSender $whatsApp,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun    = $this->option('dry-run');
        $tz        = 'Asia/Tashkent';
        $yesterday = Carbon::now($tz)->subDay()->toDateString();

        if ($dryRun) {
            $this->info('[DRY-RUN] No messages will be sent.');
        }

        $this->info("Looking for tours that ended on: {$yesterday}");

        // For multi-day tours the travel_date is the START. A 2-day tour
        // starting yesterday-1 ends yesterday. For single-day tours,
        // travel_date = yesterday = end date.
        //
        // Simple approach: find inquiries where travel_date is yesterday OR
        // (travel_date is day-before-yesterday AND tour product is multi-day).
        // For now, just check travel_date = yesterday. Multi-day tours will
        // get their review request one day early — acceptable for v1.
        $inquiries = BookingInquiry::query()
            ->where('status', BookingInquiry::STATUS_CONFIRMED)
            ->whereNull('review_request_sent_at')
            ->where('travel_date', $yesterday)
            ->get();

        if ($inquiries->isEmpty()) {
            $this->info('No tours ended yesterday — nothing to send.');

            return self::SUCCESS;
        }

        $sent = 0;
        $failed = 0;

        foreach ($inquiries as $inquiry) {
            $firstName = $this->firstName($inquiry->customer_name);
            $tourTitle = $inquiry->tourProduct?->title
                ?? preg_replace('/\s*\|\s*Jahongir\s+Travel\s*$/iu', '', (string) $inquiry->tour_name_snapshot);

            $this->info("  📬 Sending review request to {$inquiry->customer_name}");

            $message  = $this->buildWhatsAppMessage($firstName, $tourTitle);
            $sentThis = false;

            // Try WhatsApp first
            $phone = $this->whatsApp->normalizePhone($inquiry->customer_phone);
            if ($phone) {
                $this->line("     → WhatsApp: {$phone}");
                if (! $dryRun) {
                    $result = $this->whatsApp->send($phone, $message);
                    if ($result->success) {
                        $sentThis = true;
                        Log::info('TourSendReviewRequests: WhatsApp sent', [
                            'inquiry_id' => $inquiry->id,
                            'reference'  => $inquiry->reference,
                            'phone'      => $phone,
                        ]);
                    } else {
                        Log::warning('TourSendReviewRequests: WhatsApp failed', [
                            'inquiry_id' => $inquiry->id,
                            'phone'      => $phone,
                            'error'      => $result->error,
                        ]);
                    }
                } else {
                    $sentThis = true;
                }
            }

            // Fallback: email
            if (! $sentThis && filled($inquiry->customer_email)) {
                $email = trim($inquiry->customer_email);
                $this->line("     → Email: {$email}");
                if (! $dryRun) {
                    $subject   = 'Thank you for joining us! Leave us a review 🌟';
                    $emailBody = $this->buildEmailMessage($firstName, $tourTitle);
                    $mml       = "From: odilorg@gmail.com\nTo: {$email}\nSubject: {$subject}\n\n{$emailBody}";

                    $tmpFile = tempnam(sys_get_temp_dir(), 'review_') . '.eml';
                    file_put_contents($tmpFile, $mml);
                    exec('himalaya template send < ' . escapeshellarg($tmpFile) . ' 2>&1', $out, $code);
                    @unlink($tmpFile);

                    if ($code === 0) {
                        $sentThis = true;
                        Log::info('TourSendReviewRequests: email sent', [
                            'inquiry_id' => $inquiry->id,
                            'email'      => $email,
                        ]);
                    }
                } else {
                    $sentThis = true;
                }
            }

            if ($sentThis && ! $dryRun) {
                $inquiry->update(['review_request_sent_at' => now()]);
                $sent++;
                $this->info('     ✅ Sent');
            } elseif (! $sentThis && ! $dryRun) {
                $failed++;
                $this->warn('     ⚠ No channel available');
            }
        }

        $this->info("Review requests done. Sent: {$sent}, Failed: {$failed}");

        return self::SUCCESS;
    }

    private function buildWhatsAppMessage(string $firstName, string $tourTitle): string
    {
        return implode("\n", [
            "Hi {$firstName}! 😊",
            '',
            "We hope you had an amazing time on the *{$tourTitle}*! 🌟",
            '',
            "If you enjoyed the experience, we'd be so grateful if you could leave us a quick review — it really helps us a lot! ⭐",
            '',
            '📝 Google: ' . self::GOOGLE_REVIEW_URL,
            '📝 TripAdvisor: ' . self::TRIPADVISOR_REVIEW_URL,
            '',
            'Even just a few words makes a big difference. Thank you and safe travels! 🌍🙏',
            '',
            '— Jahongir Travel',
        ]);
    }

    private function buildEmailMessage(string $firstName, string $tourTitle): string
    {
        return implode("\n", [
            "Dear {$firstName},",
            '',
            "We hope you had an amazing time on the \"{$tourTitle}\"!",
            '',
            "If you enjoyed the experience, we'd be so grateful if you could leave us a quick review:",
            '',
            '⭐ Google: ' . self::GOOGLE_REVIEW_URL,
            '⭐ TripAdvisor: ' . self::TRIPADVISOR_REVIEW_URL,
            '',
            'Even just a few words makes a big difference and helps other travelers discover us.',
            '',
            'Thank you so much and safe travels!',
            '',
            'Best regards,',
            'Odiljon',
            'Jahongir Travel',
        ]);
    }

    private function firstName(string $fullName): string
    {
        $parts = preg_split('/\s+/', trim($fullName), 2);

        return $parts[0] ?? $fullName;
    }
}
