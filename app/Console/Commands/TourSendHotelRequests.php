<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

        if ($dryRun) $this->info('[DRY-RUN] No emails will be sent.');

        $this->info("Checking bookings from {$from} to {$to} with missing hotel...");

        $bookings = DB::table('bookings')
            ->join('guests', 'bookings.guest_id', '=', 'guests.id')
            ->join('tours',  'bookings.tour_id',  '=', 'tours.id')
            ->where('bookings.booking_status', 'confirmed')
            ->whereNull('bookings.hotel_request_sent_at')
            ->whereBetween(DB::raw('DATE(bookings.booking_start_date_time)'), [$from, $to])
            ->where(function ($q) {
                $q->whereNull('bookings.pickup_location')
                  ->orWhere('bookings.pickup_location', '')
                  ->orWhere('bookings.pickup_location', 'Samarkand');
            })
            ->whereNotNull('guests.email')
            ->where('guests.email', '!=', '')
            ->select([
                'bookings.id',
                'bookings.booking_number',
                'tours.title as tour_title',
                DB::raw("DATE(bookings.booking_start_date_time) as tour_date"),
                DB::raw("DATE_FORMAT(bookings.booking_start_date_time, '%M %d, %Y') as tour_date_label"),
                'guests.first_name',
                'guests.last_name',
                'guests.email',
            ])
            ->orderBy('bookings.booking_start_date_time')
            ->get();

        if ($bookings->isEmpty()) {
            $this->info('No bookings need hotel requests right now.');
            return self::SUCCESS;
        }

        $sent = 0;
        foreach ($bookings as $booking) {
            $firstName = $booking->first_name;
            $email     = trim($booking->email);

            $this->info("  📧 {$firstName} {$booking->last_name} — {$booking->tour_date} — {$email}");

            if (!$dryRun) {
                $subject = "Your {$booking->tour_title} — {$booking->tour_date_label} | Pickup Location Needed";
                $body    = $this->buildEmail($firstName, $booking->tour_title, $booking->tour_date_label);
                $mml     = "From: odilorg@gmail.com\nTo: {$email}\nSubject: {$subject}\n\n{$body}";

                $tmpFile = tempnam(sys_get_temp_dir(), 'hotel_') . '.eml';
                file_put_contents($tmpFile, $mml);
                exec("himalaya template send < " . escapeshellarg($tmpFile) . " 2>&1", $out, $code);
                unlink($tmpFile);

                if ($code === 0) {
                    DB::table('bookings')->where('id', $booking->id)->update([
                        'hotel_request_sent_at' => now(),
                    ]);
                    Log::info('TourSendHotelRequests: email sent', [
                        'booking_id' => $booking->id,
                        'email'      => $email,
                    ]);
                    $sent++;
                    $this->info("     ✅ Sent");
                } else {
                    Log::error('TourSendHotelRequests: email failed', [
                        'booking_id' => $booking->id,
                        'email'      => $email,
                        'output'     => implode(' ', $out),
                    ]);
                    $this->error("     ❌ Failed: " . implode(' ', $out));
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
            "",
            "Thank you for booking the \"{$tourTitle}\" on {$dateLabel}!",
            "",
            "To arrange your pickup, could you please let us know the name of your hotel in Samarkand?",
            "",
            "We will pick you up directly from your hotel at the scheduled time.",
            "",
            "Looking forward to a wonderful tour with you!",
            "",
            "Best regards,",
            "Odiljon",
            "Jahongir Travel",
        ]);
    }
}
