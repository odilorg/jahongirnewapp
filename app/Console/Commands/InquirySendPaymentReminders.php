<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BookingInquiry;
use App\Services\Messaging\WhatsAppSender;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Nudge guests who received a payment link but haven't paid yet.
 *
 * Phase 10.5 — the first direct revenue optimization feature.
 * A guest with an unpaid payment link is distracted, not disinterested.
 * One soft WhatsApp reminder 4+ hours after the link was sent converts
 * a meaningful percentage of "almost booked" leads.
 *
 * Guardrails:
 *   - Only awaiting_payment status (not confirmed/cancelled/spam)
 *   - Only if payment_link exists and paid_at is null
 *   - Only if payment_link_sent_at is 4+ hours ago
 *   - Only if payment_reminder_sent_at is null (one nudge per inquiry)
 *   - Only for inquiries created within the last 3 days (avoids stale leads)
 *   - Fail-soft: WhatsApp failure is logged, never retried automatically
 */
class InquirySendPaymentReminders extends Command
{
    protected $signature   = 'inquiry:send-payment-reminders {--dry-run : Print without sending}';
    protected $description = 'Send WhatsApp nudge to guests with unpaid payment links (4+ hours old)';

    private const MIN_HOURS_AFTER_LINK = 4;
    private const MAX_DAYS_OLD         = 3;

    public function __construct(
        private WhatsAppSender $whatsApp,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('[DRY-RUN] No messages will be sent.');
        }

        $inquiries = BookingInquiry::query()
            ->where('status', BookingInquiry::STATUS_AWAITING_PAYMENT)
            ->whereNotNull('payment_link')
            ->whereNull('paid_at')
            ->whereNull('payment_reminder_sent_at')
            ->where('payment_link_sent_at', '<=', now()->subHours(self::MIN_HOURS_AFTER_LINK))
            ->where('created_at', '>=', now()->subDays(self::MAX_DAYS_OLD))
            ->orderBy('payment_link_sent_at')
            ->get();

        $this->info("[inquiry:send-payment-reminders] Found {$inquiries->count()} unpaid inquiries to nudge");

        if ($inquiries->isEmpty()) {
            return self::SUCCESS;
        }

        $sent   = 0;
        $failed = 0;

        foreach ($inquiries as $inquiry) {
            $phone = $this->whatsApp->normalizePhone($inquiry->customer_phone);

            if (! $phone) {
                $this->warn("  ⚠ Skipping {$inquiry->customer_name} — no valid phone");

                continue;
            }

            $firstName = $this->firstName($inquiry->customer_name);
            $tourTitle = $inquiry->tourProduct?->title
                ?? preg_replace('/\s*\|\s*Jahongir\s+Travel\s*$/iu', '', (string) $inquiry->tour_name_snapshot);
            $dateLabel = $inquiry->travel_date?->format('F j') ?? 'your selected date';

            $message = implode("\n", [
                "Hi {$firstName}! 👋",
                '',
                "Just checking in — we sent a payment link for your {$tourTitle} on {$dateLabel}.",
                '',
                'Here it is again in case it got lost:',
                $inquiry->payment_link,
                '',
                'Let me know if you need any help 😊',
                '— Jahongir Travel',
            ]);

            $this->info("  📱 {$inquiry->reference} · {$inquiry->customer_name} · link sent {$inquiry->payment_link_sent_at->diffForHumans()}");

            if ($dryRun) {
                $sent++;

                continue;
            }

            $result = $this->whatsApp->send($phone, $message);

            if ($result->success) {
                // System-state timestamp — bypass mass-assignment so a future
                // $fillable regression cannot silently disable idempotency
                // (production incident 2026-04-26: column was missing from
                // $fillable, update() no-op'd, hourly cron resent the same
                // reminder).
                $inquiry->forceFill(['payment_reminder_sent_at' => now()])->save();

                $this->appendNote($inquiry, 'Payment reminder sent via WhatsApp');

                Log::info('InquirySendPaymentReminders: sent', [
                    'reference' => $inquiry->reference,
                    'phone'     => $phone,
                    'link_age'  => $inquiry->payment_link_sent_at->diffForHumans(),
                ]);
                $sent++;
                $this->info('     ✅ Sent');
            } else {
                Log::warning('InquirySendPaymentReminders: WhatsApp failed', [
                    'reference' => $inquiry->reference,
                    'phone'     => $phone,
                    'error'     => $result->error,
                ]);
                $failed++;
                $this->error("     ❌ Failed: {$result->error}");
            }
        }

        $this->info("[inquiry:send-payment-reminders] Done. sent={$sent} failed={$failed}");

        return self::SUCCESS;
    }

    private function firstName(string $fullName): string
    {
        $parts = preg_split('/\s+/', trim($fullName), 2);

        return $parts[0] ?? $fullName;
    }

    private function appendNote(BookingInquiry $inquiry, string $note): void
    {
        $timestamp = now()->format('Y-m-d H:i');
        $existing  = $inquiry->internal_notes ?? '';
        $separator = $existing ? "\n" : '';

        $inquiry->update([
            'internal_notes' => $existing . $separator . "[{$timestamp}] {$note}",
        ]);
    }
}
