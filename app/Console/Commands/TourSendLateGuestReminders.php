<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BookingInquiry;
use App\Services\TourReminderDispatcher;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Hourly catch-up for guest tour reminders.
 *
 * Why
 *   The daily 20:00 batch (`tour:send-reminders`) only catches bookings
 *   that exist at 20:00. Late bookings (post-20:00 for tomorrow's tour,
 *   or same-day bookings after the cutoff) silently miss the reminder.
 *
 * What
 *   Every hour, scan for confirmed inquiries whose pickup is in the
 *   next 24h AND whose `guest_reminder_sent_at` is still NULL. For each,
 *   delegate to TourReminderDispatcher::sendGuestReminder().
 *
 * Why no double-send
 *   - The 20:00 batch stamps `guest_reminder_sent_at` on the same column.
 *   - The on-confirm fast-path job stamps the same column.
 *   - All three paths re-check the marker inside the dispatcher before
 *     calling WhatsAppSender, so collisions resolve to exactly one send.
 */
class TourSendLateGuestReminders extends Command
{
    protected $signature   = 'tour:send-late-guest-reminders {--dry-run : Print without sending}';
    protected $description = 'Hourly catch-up — send guest WA reminder for confirmed bookings within the next 24h that the daily batch missed';

    public function __construct(
        private readonly TourReminderDispatcher $dispatcher,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $now    = Carbon::now('Asia/Tashkent');
        $today  = $now->copy()->toDateString();
        $tomorrow = $now->copy()->addDay()->toDateString();

        // Wide travel_date window (today + tomorrow). The dispatcher does
        // the precise pickup-time-in-24h check per inquiry, so we only
        // need a roughly-right SQL filter here.
        $candidates = BookingInquiry::query()
            ->where('status', BookingInquiry::STATUS_CONFIRMED)
            ->whereIn('travel_date', [$today, $tomorrow])
            ->whereNull('guest_reminder_sent_at')
            ->whereNotNull('customer_phone')
            ->orderBy('travel_date')
            ->orderBy('pickup_time')
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('[tour:send-late-guest-reminders] No late candidates.');
            return self::SUCCESS;
        }

        $this->info("[tour:send-late-guest-reminders] {$candidates->count()} candidates");

        $sent = $skipped = $failed = 0;

        foreach ($candidates as $inquiry) {
            if ($dryRun) {
                $departure = $this->dispatcher->departureAt($inquiry);
                $hours = $departure ? (int) round($now->diffInMinutes($departure, false) / 60) : null;
                $this->info("  [DRY] {$inquiry->reference} · {$inquiry->customer_name} · pickup in {$hours}h");
                continue;
            }

            $result = $this->dispatcher->sendGuestReminder($inquiry, source: 'hourly_catch_up');

            if ($result['ok']) {
                $sent++;
                $this->info("  ✅ {$inquiry->reference} · sent (lead={$result['lead_time_minutes']}m)");
            } elseif ($result['reason'] === 'already_sent' || $result['reason'] === 'out_of_window') {
                $skipped++;
            } else {
                $failed++;
                $this->warn("  ⚠ {$inquiry->reference} · {$result['reason']}");
            }
        }

        Log::info('tour:send-late-guest-reminders: done', [
            'sent' => $sent, 'skipped' => $skipped, 'failed' => $failed,
        ]);
        $this->info("done — sent={$sent} skipped={$skipped} failed={$failed}");

        return self::SUCCESS;
    }
}
