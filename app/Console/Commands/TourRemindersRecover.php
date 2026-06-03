<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BookingInquiry;
use Illuminate\Console\Command;

/**
 * Manual recovery for stuck / suppressed guest reminder state.
 *
 * Two operations:
 *
 *   Mark sent — use when you KNOW the guest received the reminder
 *   (e.g. they replied on WhatsApp, or operator confirmed delivery):
 *     php artisan tour:reminders:recover 152
 *
 *   Suppress — use when delivery is uncertain and you want to stop
 *   all further automatic attempts without claiming success:
 *     php artisan tour:reminders:recover 152 --suppress --reason="Guest already called us"
 *
 * Both stamp guest_reminder_last_attempted_at so the throttle window
 * resets, and both are logged explicitly.
 */
class TourRemindersRecover extends Command
{
    protected $signature = 'tour:reminders:recover
                            {inquiry_id : The booking_inquiries.id to recover}
                            {--suppress : Suppress rather than mark sent}
                            {--reason= : Reason for the manual intervention}';

    protected $description = 'Manually recover a stuck/suppressed guest reminder — mark sent or suppress';

    public function handle(): int
    {
        $inquiryId = (int) $this->argument('inquiry_id');
        $suppress = (bool) $this->option('suppress');
        $reason = $this->option('reason') ?: 'manual intervention via artisan';

        $inquiry = BookingInquiry::find($inquiryId);

        if (! $inquiry) {
            $this->error("Inquiry {$inquiryId} not found.");

            return self::FAILURE;
        }

        $this->line("Inquiry: {$inquiry->reference} · {$inquiry->customer_name}");
        $this->line('Current status: '.($inquiry->guest_reminder_status ?? 'null'));
        $this->line("Attempts: {$inquiry->guest_reminder_attempt_count}");
        $this->line('Sent at: '.($inquiry->guest_reminder_sent_at ? (string) $inquiry->guest_reminder_sent_at : 'null'));

        if ($suppress) {
            $inquiry->forceFill([
                'guest_reminder_status' => BookingInquiry::REMINDER_STATUS_SUPPRESSED,
                'guest_reminder_last_error' => 'manually suppressed: '.$reason,
                'guest_reminder_last_attempted_at' => now(),
            ])->save();

            $this->info("✅ Inquiry {$inquiry->reference} suppressed — no further automatic sends.");
            \Illuminate\Support\Facades\Log::info('TourRemindersRecover: manually suppressed', [
                'inquiry_id' => $inquiry->id,
                'reference' => $inquiry->reference,
                'reason' => $reason,
            ]);
        } else {
            $inquiry->forceFill([
                'guest_reminder_status' => BookingInquiry::REMINDER_STATUS_SENT,
                'guest_reminder_sent_at' => now(),
                'guest_reminder_last_error' => null,
                'guest_reminder_last_attempted_at' => now(),
            ])->save();

            $this->info("✅ Inquiry {$inquiry->reference} marked as sent.");
            \Illuminate\Support\Facades\Log::info('TourRemindersRecover: manually marked sent', [
                'inquiry_id' => $inquiry->id,
                'reference' => $inquiry->reference,
                'reason' => $reason,
            ]);
        }

        return self::SUCCESS;
    }
}
