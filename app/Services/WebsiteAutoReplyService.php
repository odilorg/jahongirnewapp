<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BookingInquiry;
use App\Services\Messaging\WhatsAppSender;
use Illuminate\Support\Facades\Log;

/**
 * Send an instant WhatsApp confirmation when a website booking form is
 * submitted. Runs fire-and-forget in the controller post-persist path.
 *
 * Guard conditions (all must be true):
 *   - source = website
 *   - customer_phone is present and normalizable
 *   - status = new (not spam, not already contacted)
 *   - travel_date is not in the past
 *   - inquiry was created within the last 2 minutes (prevents replay/import triggers)
 *
 * On success: appends a note to internal_notes.
 * On failure: logs the error, does NOT retry. Operator follows up manually.
 * Does NOT set contacted_at — that's reserved for operator personal contact.
 */
class WebsiteAutoReplyService
{
    public function __construct(
        private WhatsAppSender $whatsApp,
    ) {
    }

    public function sendIfEligible(BookingInquiry $inquiry): void
    {
        if (! $this->isEligible($inquiry)) {
            return;
        }

        $phone = $this->whatsApp->normalizePhone($inquiry->customer_phone);
        if (! $phone) {
            Log::info('WebsiteAutoReply: phone not normalizable, skipping', [
                'reference' => $inquiry->reference,
                'phone'     => $inquiry->customer_phone,
            ]);

            return;
        }

        $message = $this->buildMessage($inquiry);

        try {
            $result = $this->whatsApp->send($phone, $message);

            if ($result->ok) {
                $this->appendNote($inquiry, 'Auto-confirmation sent via WhatsApp');

                Log::info('WebsiteAutoReply: sent', [
                    'reference' => $inquiry->reference,
                    'phone'     => $phone,
                ]);
            } else {
                $this->appendNote($inquiry, "Auto-confirmation failed: {$result->error}");

                Log::warning('WebsiteAutoReply: send failed', [
                    'reference' => $inquiry->reference,
                    'error'     => $result->error,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('WebsiteAutoReply: exception (inquiry saved, operator will follow up)', [
                'reference' => $inquiry->reference,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    private function isEligible(BookingInquiry $inquiry): bool
    {
        if ($inquiry->source !== BookingInquiry::SOURCE_WEBSITE) {
            return false;
        }

        if (empty($inquiry->customer_phone)) {
            return false;
        }

        if ($inquiry->status !== BookingInquiry::STATUS_NEW) {
            return false;
        }

        // Don't auto-reply for past dates (likely a form error)
        if ($inquiry->travel_date && $inquiry->travel_date->isPast()) {
            return false;
        }

        // Only for freshly created inquiries — prevents import/backfill triggers
        if ($inquiry->created_at && $inquiry->created_at->diffInMinutes(now()) > 2) {
            return false;
        }

        return true;
    }

    private function buildMessage(BookingInquiry $inquiry): string
    {
        $name = $this->firstName($inquiry->customer_name);
        $tour = $inquiry->tour_name_snapshot ?: 'your selected tour';

        // Clean tour name — strip "| Jahongir Travel" suffix
        $tour = preg_replace('/\s*\|\s*Jahongir\s+Travel\s*$/iu', '', $tour) ?? $tour;

        $date = $inquiry->travel_date
            ? $inquiry->travel_date->format('F j, Y')
            : 'your selected date';

        $pax = $inquiry->people_adults;
        $paxLabel = $pax > 1 ? "{$pax} guests" : '1 guest';
        if ($inquiry->people_children > 0) {
            $paxLabel .= " + {$inquiry->people_children} children";
        }

        $pickup = filled($inquiry->pickup_point)
            ? $inquiry->pickup_point
            : 'Please share your hotel name and we\'ll arrange pickup';

        $lines = [
            "Hi {$name}! 👋",
            "Your private {$tour} is confirmed! ✅",
            '',
            'Please check these details:',
            "📅 Date: {$date}",
            "👥 Guests: {$paxLabel}",
            "🏨 Pickup: {$pickup}",
            '',
            'Please reply "all correct" or send any changes.',
            '',
            '💳 Payment options:',
            '• Secure payment link (we\'ll send one shortly)',
            '• Cash on tour day',
            '• Card at our office in Samarkand',
            '',
            '— Jahongir Travel, Samarkand',
        ];

        return implode("\n", $lines);
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
