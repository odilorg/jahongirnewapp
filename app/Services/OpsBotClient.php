<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin Telegram bot adapter for ops alerts.
 *
 * One opinionated chat target — the operator DM (config:
 * services.ops_bot.token + services.ops_bot.owner_chat_id). Used by
 * BookingInquiryNotifier (legacy direct call still inline there) and the
 * feedback low-rating alert. Centralised here so future migrations to a
 * separate "ops feedback" group only touch one file.
 *
 * Never throws — callers must remain happy-path even when Telegram is down.
 */
class OpsBotClient
{
    public function send(string $text, bool $html = true): bool
    {
        $token  = (string) config('services.ops_bot.token');
        $chatId = (string) config('services.ops_bot.owner_chat_id');

        if ($token === '' || $chatId === '') {
            Log::warning('OpsBotClient: ops_bot not configured — skipping');

            return false;
        }

        try {
            $response = Http::timeout(5)
                ->connectTimeout(3)
                ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id'                  => $chatId,
                    'text'                     => $text,
                    'parse_mode'               => $html ? 'HTML' : null,
                    'disable_web_page_preview' => true,
                ]);
        } catch (\Throwable $e) {
            Log::warning('OpsBotClient: HTTP exception', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if (! $response->successful()) {
            Log::warning('OpsBotClient: non-2xx response', [
                'status' => $response->status(),
                'body'   => mb_substr($response->body(), 0, 300),
            ]);

            return false;
        }

        return true;
    }
}
