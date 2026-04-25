<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Actions\Calendar\Support\CalendarActionResult;
use App\Actions\Payment\Support\ReceiptContext;
use App\Mail\BookingPaymentReceiptMail;
use App\Models\BookingInquiry;
use App\Services\Messaging\WhatsAppSender;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Send a payment receipt to the guest via email and/or WhatsApp.
 *
 * Guards (in order):
 *   1. Status guard — inquiry must be STATUS_CONFIRMED with paid_at set.
 *      Prevents receipts for pending_review, superseded, or cancelled payments.
 *   2. Idempotency guard — skip if receipt_sent_at is already set.
 *      Bypassed via $force = true (Filament manual resend only).
 *
 * Channel logic:
 *   - email + phone  → send both
 *   - email only     → send email only
 *   - phone only     → send WhatsApp only
 *   - neither        → log warning, return failure without stamping
 *
 * Stamping:
 *   receipt_sent_at is set via a raw query-builder update (no updated_at bump)
 *   only after at least one channel succeeds. Never cleared on resend.
 *
 * Failures:
 *   Per-channel exceptions are caught and logged. The action never throws.
 *   Callers (OctoCallbackController) wrap in an outer try/catch as belt-and-
 *   suspenders — webhook must return 200 regardless.
 *
 * PII policy:
 *   Logs contain reference + channel booleans only. Never email/phone/name.
 */
final class SendReceiptAction
{
    public function __construct(
        private readonly WhatsAppSender $whatsApp,
    ) {}

    public function execute(
        BookingInquiry $inquiry,
        bool $force = false,
        string $uzsAmountRaw = '',
    ): CalendarActionResult {
        // Refresh first — the controller passes a stale object; paid_at is
        // set by the GuestPayment observer AFTER the inquiry status update.
        $inquiry->refresh();

        // Guard 1: status — only confirmed + paid inquiries get a receipt.
        if ($inquiry->status !== BookingInquiry::STATUS_CONFIRMED || $inquiry->paid_at === null) {
            Log::info('SendReceiptAction: skipped — not confirmed/paid', [
                'reference' => $inquiry->reference,
                'status'    => $inquiry->status,
                'paid_at'   => $inquiry->paid_at?->toIso8601String(),
                'forced'    => $force,
            ]);
            return CalendarActionResult::failure('Inquiry is not confirmed or paid — receipt not sent');
        }

        // Guard 2: idempotency — skip duplicates unless operator forces resend.
        if (! $force && $inquiry->receipt_sent_at !== null) {
            return CalendarActionResult::success('Receipt already sent', ['skipped' => true]);
        }

        $ctx = ReceiptContext::fromInquiry($inquiry, $uzsAmountRaw);

        $emailOk = false;
        $waOk    = false;
        $emailAttempted = false;
        $waAttempted    = false;

        // Channel: email
        if (filled($inquiry->customer_email)) {
            $emailAttempted = true;
            $emailOk = $this->attemptEmail($inquiry, $ctx);
        }

        // Channel: WhatsApp
        $normalizedPhone = $this->whatsApp->normalizePhone($inquiry->customer_phone);
        if ($normalizedPhone !== null) {
            $waAttempted = true;
            $waOk = $this->attemptWhatsApp($inquiry, $ctx, $normalizedPhone);
        }

        // No channel available at all
        if (! $emailAttempted && ! $waAttempted) {
            Log::warning('SendReceiptAction: no deliverable contact channel', [
                'reference' => $inquiry->reference,
            ]);
            return CalendarActionResult::failure('No deliverable contact channel — receipt not sent');
        }

        $anySuccess = $emailOk || $waOk;

        if ($anySuccess) {
            $this->markSent($inquiry);
        }

        $channels = match (true) {
            $emailOk && $waOk    => 'both',
            $emailOk             => 'email',
            $waOk                => 'whatsapp',
            default              => 'none',
        };

        $logContext = [
            'reference' => $inquiry->reference,
            'channels'  => $channels,
            'email_ok'  => $emailOk,
            'wa_ok'     => $waOk,
            'forced'    => $force,
        ];

        if ($anySuccess) {
            Log::info('SendReceiptAction: receipt sent', $logContext);
            return CalendarActionResult::success('Receipt sent', [
                'channels' => $channels,
                'email_ok' => $emailOk,
                'wa_ok'    => $waOk,
            ]);
        }

        Log::warning('SendReceiptAction: all channels failed', $logContext);
        return CalendarActionResult::failure('All channels failed — receipt not sent', [
            'email_ok' => $emailOk,
            'wa_ok'    => $waOk,
        ]);
    }

    private function attemptEmail(BookingInquiry $inquiry, ReceiptContext $ctx): bool
    {
        try {
            Mail::to($inquiry->customer_email)
                ->send(new BookingPaymentReceiptMail($inquiry, $ctx));
            return true;
        } catch (\Throwable $e) {
            Log::warning('SendReceiptAction: email failed', [
                'reference' => $inquiry->reference,
                'error'     => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function attemptWhatsApp(BookingInquiry $inquiry, ReceiptContext $ctx, string $normalizedPhone): bool
    {
        try {
            $message = $this->buildWhatsAppMessage($inquiry, $ctx);
            $result  = $this->whatsApp->send($normalizedPhone, $message);

            if (! $result->success) {
                Log::warning('SendReceiptAction: WhatsApp failed', [
                    'reference' => $inquiry->reference,
                    'error'     => $result->error,
                ]);
            }

            return $result->success;
        } catch (\Throwable $e) {
            Log::warning('SendReceiptAction: WhatsApp exception', [
                'reference' => $inquiry->reference,
                'error'     => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function buildWhatsAppMessage(BookingInquiry $inquiry, ReceiptContext $ctx): string
    {
        $date = $inquiry->travel_date?->format('M j, Y') ?? '—';
        $pax  = $ctx->paxLine($inquiry);
        $ref  = $inquiry->reference;
        $tour = $inquiry->tour_name_snapshot ?? '—';

        $lines = [
            '✅ Booking confirmed!',
            '',
            "Ref: {$ref}",
            "Tour: {$tour}",
            "Date: {$date}",
            "People: {$pax}",
        ];

        if ($ctx->onlinePaidUsd > 0) {
            $lines[] = sprintf('Paid: $%.2f USD', $ctx->onlinePaidUsd);
        }

        if ($ctx->isPartial) {
            $lines[] = sprintf('Remaining: $%.2f USD cash at pickup.', $ctx->remainingCashUsd);
        }

        $lines[] = '';

        if (filled($inquiry->customer_email)) {
            $lines[] = 'Full receipt sent to your email.';
        } else {
            $lines[] = 'Your booking details are confirmed above.';
        }

        $lines[] = 'See you soon!';
        $lines[] = 'Jahongir Travel';

        return implode("\n", $lines);
    }

    /**
     * Stamp receipt_sent_at without touching updated_at.
     * Uses query-builder update matching DriverDispatchNotifier precedent.
     */
    private function markSent(BookingInquiry $inquiry): void
    {
        DB::table('booking_inquiries')
            ->where('id', $inquiry->id)
            ->update(['receipt_sent_at' => now()]);
    }
}
