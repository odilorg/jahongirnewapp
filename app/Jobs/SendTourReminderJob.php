<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\BookingInquiry;
use App\Services\TourReminderDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Fast-path guest tour reminder.
 *
 * Dispatched from ConfirmBookingAction when travel is within 24h, so the
 * operator's "Confirm booking" click immediately triggers the WhatsApp
 * reminder instead of waiting for the next hourly cron tick.
 *
 * Race-safe: re-fetches the inquiry, re-checks `guest_reminder_sent_at`,
 * and re-validates the 24h window inside the job. The hourly cron does
 * the same checks, so a job + cron-tick collision results in exactly
 * one send (whichever stamps the marker first wins; the other no-ops).
 */
class SendTourReminderJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public readonly int $inquiryId,
    ) {}

    public function handle(TourReminderDispatcher $dispatcher): void
    {
        $inquiry = BookingInquiry::find($this->inquiryId);

        if (! $inquiry) {
            Log::info('SendTourReminderJob: inquiry vanished', ['inquiry_id' => $this->inquiryId]);
            return;
        }

        if ($inquiry->status !== BookingInquiry::STATUS_CONFIRMED) {
            Log::info('SendTourReminderJob: inquiry not confirmed (status changed)', [
                'inquiry_id' => $inquiry->id,
                'status'     => $inquiry->status,
            ]);
            return;
        }

        if ($inquiry->guest_reminder_sent_at !== null) {
            Log::info('SendTourReminderJob: already sent, skipping', [
                'inquiry_id' => $inquiry->id,
                'sent_at'    => (string) $inquiry->guest_reminder_sent_at,
            ]);
            return;
        }

        // Re-validate the 24h window — the job may have queued for a while.
        $departureAt = $dispatcher->departureAt($inquiry);
        if ($departureAt === null) {
            Log::warning('SendTourReminderJob: cannot compute departure_at, skipping', [
                'inquiry_id' => $inquiry->id,
            ]);
            return;
        }

        $hoursUntil = now('Asia/Tashkent')->diffInHours($departureAt, false);
        if ($hoursUntil > 24 || $hoursUntil < 0) {
            Log::info('SendTourReminderJob: outside 24h window, skipping', [
                'inquiry_id'  => $inquiry->id,
                'hours_until' => $hoursUntil,
            ]);
            return;
        }

        $dispatcher->sendGuestReminder($inquiry, source: 'on_confirm_fast_path');
    }
}
