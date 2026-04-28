<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BookingInquiry;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Email guests with confirmed bookings (3-30 days out) who have no
 * hotel/pickup location set. Asks them to share their hotel name so
 * the operator can arrange pickup.
 *
 * Repointed from legacy `bookings` to `booking_inquiries` in Phase 9.2.
 * Send channel: email via himalaya CLI (same as original).
 */
class TourSendHotelRequests extends Command
{
    protected $signature   = 'tour:send-hotel-requests {--dry-run : Print without sending}';
    protected $description = 'Email guests with confirmed bookings (3-30 days out) who have no hotel/pickup location';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $tz     = 'Asia/Tashkent';
        $from   = Carbon::now($tz)->addDays(3)->toDateString();
        $to     = Carbon::now($tz)->addDays(30)->toDateString();

        if ($dryRun) {
            $this->info('[DRY-RUN] No emails will be sent.');
        }

        $this->info("Checking inquiries from {$from} to {$to} with missing hotel...");

        $inquiries = BookingInquiry::query()
            ->where('status', BookingInquiry::STATUS_CONFIRMED)
            ->whereNull('hotel_request_sent_at')
            ->whereBetween('travel_date', [$from, $to])
            ->where(function ($q) {
                $q->whereNull('pickup_point')
                  ->orWhere('pickup_point', '')
                  ->orWhere('pickup_point', 'Samarkand')
                  ->orWhere('pickup_point', 'Gur Emir Mausoleum');
            })
            ->where('customer_email', '!=', '')
            ->orderBy('travel_date')
            ->get();

        if ($inquiries->isEmpty()) {
            $this->info('No inquiries need hotel requests right now.');

            return self::SUCCESS;
        }

        $sent = 0;
        foreach ($inquiries as $inquiry) {
            $firstName = $this->firstName($inquiry->customer_name);
            $email     = trim($inquiry->customer_email);
            $tourTitle = $inquiry->tourProduct?->title
                ?? preg_replace('/\s*\|\s*Jahongir\s+Travel\s*$/iu', '', (string) $inquiry->tour_name_snapshot);
            $dateLabel = $inquiry->travel_date->format('F d, Y');

            $this->info("  📧 {$inquiry->customer_name} — {$inquiry->travel_date->format('Y-m-d')} — {$email}");

            if (! $dryRun) {
                $subject = "Your {$tourTitle} — {$dateLabel} | Pickup Location Needed";
                $body    = $this->buildEmail($firstName, $tourTitle, $dateLabel);
                $mml     = "From: odilorg@gmail.com\nTo: {$email}\nSubject: {$subject}\n\n{$body}";

                $tmpFile = tempnam(sys_get_temp_dir(), 'hotel_') . '.eml';
                file_put_contents($tmpFile, $mml);
                exec('himalaya template send < ' . escapeshellarg($tmpFile) . ' 2>&1', $out, $code);
                @unlink($tmpFile);

                if ($code === 0) {
                    // forceFill+save bypasses $fillable; same bug pattern as
                    // TourSendReviewRequests caused INQ-2026-000015 to receive
                    // the hotel email 5 times across Apr 22-28 (silent drop).
                    $inquiry->forceFill(['hotel_request_sent_at' => now()])->save();

                    Log::info('TourSendHotelRequests: email sent', [
                        'inquiry_id' => $inquiry->id,
                        'reference'  => $inquiry->reference,
                        'email'      => $email,
                    ]);
                    $sent++;
                    $this->info('     ✅ Sent');
                } else {
                    Log::error('TourSendHotelRequests: email failed', [
                        'inquiry_id' => $inquiry->id,
                        'email'      => $email,
                        'output'     => implode(' ', $out),
                    ]);
                    $this->error('     ❌ Failed: ' . implode(' ', $out));
                }

                usleep(500000); // 0.5s between sends
            } else {
                $sent++;
            }
        }

        $this->info("Hotel requests done. Sent: {$sent}");

        return self::SUCCESS;
    }

    private function buildEmail(string $firstName, string $tourTitle, string $dateLabel): string
    {
        return implode("\n", [
            "Dear {$firstName},",
            '',
            "Thank you for booking the \"{$tourTitle}\" on {$dateLabel}!",
            '',
            'To arrange your pickup, could you please let us know the name of your hotel in Samarkand?',
            '',
            'We will pick you up directly from your hotel at the scheduled time.',
            '',
            'Looking forward to a wonderful tour with you!',
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
