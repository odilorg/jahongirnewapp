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
        $this->send($inquiry, $this->buildMessage($inquiry));
    }

    /**
     * Send a 💰 "payment received" Telegram notification when Octo's
     * webhook confirms a successful payment for an inquiry. Called from
     * OctoCallbackController::handleInquiryCallback().
     */
    public function notifyPaid(BookingInquiry $inquiry, string $uzsAmount = ''): void
    {
        $this->send($inquiry, $this->buildPaidMessage($inquiry, $uzsAmount));
    }

    /**
     * Red-flag notification: a successful payment arrived for an inquiry
     * that was already marked cancelled or spam. Do not auto-confirm —
     * the operator needs to investigate and decide what to do (refund,
     * honour the booking, etc.). This message is deliberately alarming
     * so it doesn't get mistaken for a routine "paid" ping.
     */
    public function notifyPaidOnTerminalStatus(BookingInquiry $inquiry, string $priorStatus, string $uzsAmount = ''): void
    {
        $lines = [
            '🚨 <b>PAYMENT ON ' . mb_strtoupper($priorStatus) . ' INQUIRY — REVIEW NEEDED</b>',
            "<code>{$inquiry->reference}</code>",
            '',
            '🧳 <b>Tour:</b> ' . htmlspecialchars((string) $inquiry->tour_name_snapshot, ENT_QUOTES, 'UTF-8'),
            '👤 <b>Customer:</b> ' . htmlspecialchars((string) $inquiry->customer_name, ENT_QUOTES, 'UTF-8'),
            '📱 ' . htmlspecialchars((string) $inquiry->customer_phone, ENT_QUOTES, 'UTF-8'),
            '',
            '⚠️ This inquiry was in <b>' . $priorStatus . '</b> status when the payment arrived.',
            '⚠️ Status has NOT been auto-confirmed. <b>Human decision required:</b>',
            '   • Honour the booking? → mark confirmed manually',
            '   • Refund the customer? → contact Octobank',
            '',
            '💵 Paid: ' . ($uzsAmount !== '' ? number_format((float) $uzsAmount) . ' UZS' : 'see Octo dashboard'),
        ];

        $this->send($inquiry, implode("\n", $lines));
    }

    private function send(BookingInquiry $inquiry, string $text): void
    {
        $token  = (string) config('services.ops_bot.token');
        $chatId = (string) config('services.ops_bot.owner_chat_id');

        if ($token === '' || $chatId === '') {
            Log::warning('BookingInquiryNotifier: ops_bot not configured — skipping', [
                'reference' => $inquiry->reference,
            ]);

            return;
        }

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

    private function buildPaidMessage(BookingInquiry $inquiry, string $uzsAmount): string
    {
        $quoted = $inquiry->price_quoted
            ? '$' . number_format((float) $inquiry->price_quoted, 2)
            : '—';

        $lines = [
            '💰 <b>Payment received</b>',
            "<code>{$inquiry->reference}</code>",
            '',
            '🧳 <b>Tour:</b> ' . htmlspecialchars((string) $inquiry->tour_name_snapshot, ENT_QUOTES, 'UTF-8'),
            '👤 <b>Customer:</b> ' . htmlspecialchars((string) $inquiry->customer_name, ENT_QUOTES, 'UTF-8'),
            "💵 <b>Quoted:</b> {$quoted} USD",
        ];

        if ($uzsAmount !== '' && $uzsAmount !== null) {
            $lines[] = '🧾 <b>Paid:</b> ' . number_format((float) $uzsAmount) . ' UZS';
        }

        $lines[] = '';
        $lines[] = '✅ Inquiry marked confirmed. Ready for booking handoff.';

        return implode("\n", $lines);
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
