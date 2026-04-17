<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\InquiryReminderMail;
use App\Models\InquiryReminder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Phase 21 — email pending reminders that are due.
 * Runs every 15 min via scheduler. Idempotent: sends once per
 * reminder (guarded by notified_at).
 */
class InquirySendReminders extends Command
{
    protected $signature   = 'inquiry:send-reminders';
    protected $description = 'Email operator reminders that are due (once)';

    public function handle(): int
    {
        $due = InquiryReminder::pending()
            ->whereNull('notified_at')
            ->where('remind_at', '<=', now())
            ->with(['bookingInquiry', 'assignedToUser', 'createdByUser'])
            ->get();

        $this->info("Found {$due->count()} due reminders");

        $sent = 0;
        $skipped = 0;
        foreach ($due as $r) {
            $user = $r->assignedToUser ?? $r->createdByUser;
            if (! $user || ! $user->email) {
                $r->update(['notified_at' => now()]); // mark so we don't loop
                $skipped++;
                continue;
            }

            try {
                Mail::to($user->email)->send(new InquiryReminderMail($r));
                $r->update(['notified_at' => now()]);
                $sent++;

                Log::info('InquiryReminder: email sent', [
                    'reminder_id' => $r->id,
                    'inquiry_ref' => $r->bookingInquiry?->reference,
                    'to'          => $user->email,
                ]);
            } catch (\Throwable $e) {
                Log::warning('InquiryReminder: email send failed', [
                    'reminder_id' => $r->id,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        $this->info("Sent: {$sent} · Skipped (no email): {$skipped}");

        return self::SUCCESS;
    }
}
