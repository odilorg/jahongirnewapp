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

        // All guards (sent_at, status, window, throttle, suppression)
        // live inside the dispatcher — the single source of truth.
        $dispatcher->sendGuestReminder($inquiry, source: 'on_confirm_fast_path');
    }
}
