<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TourSendReviewRequests extends Command
{
    protected $signature   = 'tour:send-review-requests {--dry-run : Print without sending}';
    protected $description = 'Send post-tour review requests to guests whose tour ended yesterday';

    // Review links
    const GOOGLE_REVIEW_URL     = 'https://g.page/r/CYoiUJW5aowWEAE/review';
    const TRIPADVISOR_REVIEW_URL = 'https://www.tripadvisor.com/UserReviewEdit-g298068-d17464942-Jahongir_Travel-Samarkand_Samarqand_Province.html';

    public function handle(): int
    {
        $dryRun   = $this->option('dry-run');
        $tz       = 'Asia/Tashkent';
        $yesterday = Carbon::now($tz)->subDay()->toDateString();

        if ($dryRun) $this->info('[DRY-RUN] No messages will be sent.');

        $this->info("Looking for tours that ended on: {$yesterday}");

        // For multi-day tours (Yurt Camp = 2 days), the tour START date is yesterday-1
        // For single-day tours, the tour START date is yesterday
        // We check bookings where the tour END date = yesterday
        // tour_duration_days defaults to 1 if not set
        $bookings = DB::table('bookings')
            ->join('guests', 'bookings.guest_id', '=', 'guests.id')
            ->join('tours',  'bookings.tour_id',  '=', 'tours.id')
            ->where('bookings.booking_status', 'confirmed')
            ->whereNull('bookings.review_request_sent_at')
            ->whereRaw("DATE(DATE_ADD(bookings.booking_start_date_time, INTERVAL CASE WHEN tours.tour_duration LIKE '2%' THEN 1 ELSE 0 END DAY)) = ?", [$yesterday])
            ->select([
                'bookings.id',
                'bookings.booking_number',
                'bookings.booking_source',
                'tours.title as tour_title',
                'guests.first_name',
                'guests.last_name',
                'guests.phone',
                'guests.email',
            ])
            ->get();

        if ($bookings->isEmpty()) {
            $this->info('No tours ended yesterday — nothing to send.');
            return self::SUCCESS;
        }

        foreach ($bookings as $booking) {
            $firstName = $booking->first_name;
            $this->info("  📬 Sending review request to {$firstName} {$booking->last_name}");

            $message = $this->buildMessage($firstName, $booking->tour_title);

            $sent = false;

            // Try WhatsApp first
            $phone = $this->normalizePhone(trim($booking->phone ?? ''));
            if ($phone) {
                $this->line("     → WhatsApp: {$phone}");
                if (!$dryRun) {
                    $jid = ltrim($phone, '+') . '@s.whatsapp.net';
                    $cmd = "wacli send text --to " . escapeshellarg($jid) . " --message " . escapeshellarg($message) . " 2>&1";
                    exec($cmd, $out, $code);
                    if ($code === 0) {
                        $sent = true;
                        Log::info('TourSendReviewRequests: WhatsApp sent', ['booking_id' => $booking->id, 'phone' => $phone]);
                    } else {
                        Log::warning('TourSendReviewRequests: WhatsApp failed', ['phone' => $phone, 'output' => implode(' ', $out)]);
                    }
                } else {
                    $sent = true;
                }
            }

            // Fallback: email (GYG relay or direct)
            if (!$sent && !empty(trim($booking->email ?? ''))) {
                $email = trim($booking->email);
                $this->line("     → Email: {$email}");
                if (!$dryRun) {
                    $subject = "Thank you for joining us! Leave us a review 🌟";
                    $emailBody = $this->buildEmailMessage($firstName, $booking->tour_title);
                    $mml = "From: odilorg@gmail.com\nTo: {$email}\nSubject: {$subject}\n\n{$emailBody}";
                    $tmpFile = tempnam(sys_get_temp_dir(), 'review_') . '.eml';
                    file_put_contents($tmpFile, $mml);
                    exec("himalaya template send < " . escapeshellarg($tmpFile) . " 2>&1", $out, $code);
                    unlink($tmpFile);
                    if ($code === 0) {
                        $sent = true;
                        Log::info('TourSendReviewRequests: email sent', ['booking_id' => $booking->id, 'email' => $email]);
                    }
                } else {
                    $sent = true;
                }
            }

            if ($sent && !$dryRun) {
                DB::table('bookings')->where('id', $booking->id)->update([
                    'review_request_sent_at' => now(),
                ]);
            }
        }

        $this->info('Review requests done.');
        return self::SUCCESS;
    }

    private function buildMessage(string $firstName, string $tourTitle): string
    {
        return implode("\n", [
            "Hi {$firstName}! 😊",
            "",
            "We hope you had an amazing time on the *{$tourTitle}*! 🌟",
            "",
            "If you enjoyed the experience, we'd be so grateful if you could leave us a quick review — it really helps us a lot! ⭐",
            "",
            "📝 Google: " . self::GOOGLE_REVIEW_URL,
            "📝 TripAdvisor: " . self::TRIPADVISOR_REVIEW_URL,
            "",
            "Even just a few words makes a big difference. Thank you and safe travels! 🌍🙏",
            "",
            "— Jahongir Travel",
        ]);
    }

    private function buildEmailMessage(string $firstName, string $tourTitle): string
    {
        return implode("\n", [
            "Dear {$firstName},",
            "",
            "We hope you had an amazing time on the \"{$tourTitle}\"!",
            "",
            "If you enjoyed the experience, we'd be so grateful if you could leave us a quick review:",
            "",
            "⭐ Google: " . self::GOOGLE_REVIEW_URL,
            "⭐ TripAdvisor: " . self::TRIPADVISOR_REVIEW_URL,
            "",
            "Even just a few words makes a big difference and helps other travelers discover us.",
            "",
            "Thank you so much and safe travels!",
            "",
            "Best regards,",
            "Odiljon",
            "Jahongir Travel",
        ]);
    }

    private function normalizePhone(string $raw): ?string
    {
        $stripped = preg_replace('/[\s\-().]+/', '', $raw);
        if (str_starts_with($stripped, '00')) $stripped = '+' . substr($stripped, 2);
        if (!str_starts_with($stripped, '+')) $stripped = '+' . $stripped;
        $digits = preg_replace('/\D/', '', $stripped);
        return (strlen($digits) >= 7 && strlen($digits) <= 15) ? $stripped : null;
    }
}
