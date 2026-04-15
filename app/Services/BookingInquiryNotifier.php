<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BookingInquiry;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Send operator Telegram pings for new website booking inquiries.
 *
 * Uses the existing `ops_bot` credentials (OPS_BOT_TOKEN + TELEGRAM_OWNER_CHAT_ID)
 * rather than reaching into the booking-bot transport infrastructure — new
 * module, fewer coupling points, easier to reason about in isolation.
 *
 * Fire-and-forget: any failure is logged but never rethrown. The controller
 * catches Throwable around this call for belt-and-suspenders.
 */
class BookingInquiryNotifier
{
    public function notify(BookingInquiry $inquiry): void
    {
        $token  = (string) config('services.ops_bot.token');
        $chatId = (string) config('services.ops_bot.owner_chat_id');

        if ($token === '' || $chatId === '') {
            Log::warning('BookingInquiryNotifier: ops_bot not configured — skipping', [
                'reference' => $inquiry->reference,
            ]);

            return;
        }

        $text = $this->buildMessage($inquiry);

        $response = Http::timeout(5)
            ->connectTimeout(3)
            ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id'                  => $chatId,
                'text'                     => $text,
                'parse_mode'               => 'HTML',
                'disable_web_page_preview' => true,
            ]);

        if (! $response->successful()) {
            Log::warning('BookingInquiryNotifier: Telegram API non-2xx', [
                'reference' => $inquiry->reference,
                'status'    => $response->status(),
                'body'      => mb_substr($response->body(), 0, 300),
            ]);
        }
    }

    private function buildMessage(BookingInquiry $inquiry): string
    {
        $pax = $inquiry->people_adults
            + $inquiry->people_children;

        $paxLine = $inquiry->people_children > 0
            ? "{$inquiry->people_adults} adults + {$inquiry->people_children} children ({$pax} total)"
            : "{$inquiry->people_adults} adults";

        $travelDate = $inquiry->travel_date
            ? $inquiry->travel_date->format('Y-m-d') . ($inquiry->flexible_dates ? ' (flexible)' : '')
            : 'not specified';

        $phone = htmlspecialchars($inquiry->customer_phone, ENT_QUOTES, 'UTF-8');
        $waPhone = preg_replace('/[^0-9]/', '', $inquiry->customer_phone);

        $lines = [
            '🆕 <b>New website inquiry</b>',
            "<code>{$inquiry->reference}</code>",
            '',
            '🧳 <b>Tour:</b> ' . htmlspecialchars($inquiry->tour_name_snapshot, ENT_QUOTES, 'UTF-8'),
            "👥 <b>Pax:</b> {$paxLine}",
            "📅 <b>Date:</b> {$travelDate}",
            '',
            '👤 <b>' . htmlspecialchars($inquiry->customer_name, ENT_QUOTES, 'UTF-8') . '</b>',
            '📧 ' . htmlspecialchars($inquiry->customer_email, ENT_QUOTES, 'UTF-8'),
            "📱 <a href=\"https://wa.me/{$waPhone}\">{$phone}</a>",
        ];

        if (filled($inquiry->message)) {
            $lines[] = '';
            $lines[] = '💬 ' . htmlspecialchars(
                mb_substr($inquiry->message, 0, 400),
                ENT_QUOTES,
                'UTF-8'
            );
        }

        if (filled($inquiry->page_url)) {
            $lines[] = '';
            $lines[] = '🔗 ' . htmlspecialchars($inquiry->page_url, ENT_QUOTES, 'UTF-8');
        }

        return implode("\n", $lines);
    }
}
