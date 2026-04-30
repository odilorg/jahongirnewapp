<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin HTTP wrapper around the tg-direct service running on vps-main
 * (bound to 127.0.0.1:8766 there, reached locally via a persistent
 * autossh reverse tunnel managed by systemd unit tg-direct-tunnel).
 *
 * tg-direct sends Telegram DMs from Odil's personal Telethon session —
 * meaning the recipient does NOT need to have started a bot chat first.
 * Accepts raw phone numbers (e.g. +998901234567), @usernames, or numeric
 * chat_ids.
 *
 * Deliberately minimal: build the request, return the response, never
 * throw upward. Callers (DriverDispatchNotifier etc.) already handle
 * the "don't break the admin UI on delivery failure" policy.
 */
class TgDirectClient
{
    /**
     * Send a Telegram DM.
     *
     * @param  string  $to        phone (+998...), @username, or chat_id
     * @param  string  $message   plain text (tg-direct does not accept HTML)
     * @param  string|null  $contactName  full name to use IF tg-direct has to
     *     import the phone as a new contact (drivers/guides/accommodation
     *     name). Ignored when the recipient is already in Odil's contacts —
     *     tg-direct will use the existing peer instead. Prevents the
     *     "every supplier renamed to 'Contact'" regression.
     * @return array{ok: bool, msg_id?: int, error?: string, method?: string}
     */
    public function send(string $to, string $message, ?string $contactName = null): array
    {
        $url     = (string) config('services.tg_direct.url', 'http://127.0.0.1:8766');
        $timeout = (int) config('services.tg_direct.timeout', 5);

        $normalised = $this->normaliseDestination($to);

        if ($normalised === '') {
            Log::warning('TgDirectClient: empty destination after normalisation', [
                'input' => $to,
            ]);

            return ['ok' => false, 'error' => 'empty_destination'];
        }

        $payload = ['to' => $normalised, 'message' => $message];
        if ($contactName !== null && trim($contactName) !== '') {
            $payload['first_name'] = trim($contactName);
        }

        try {
            $response = Http::timeout($timeout)
                ->connectTimeout(3)
                ->asJson()
                ->post(rtrim($url, '/') . '/send', $payload);
        } catch (\Throwable $e) {
            Log::warning('TgDirectClient: HTTP exception', [
                'to'    => $normalised,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'error' => 'http_exception: ' . $e->getMessage()];
        }

        if (! $response->successful()) {
            Log::warning('TgDirectClient: non-2xx response', [
                'to'     => $normalised,
                'status' => $response->status(),
                'body'   => mb_substr($response->body(), 0, 300),
            ]);

            return ['ok' => false, 'error' => 'http_' . $response->status()];
        }

        $json = $response->json();

        if (! is_array($json)) {
            return ['ok' => false, 'error' => 'invalid_json'];
        }

        return $json;
    }

    /**
     * Normalise a destination into a form tg-direct accepts.
     *
     * - @username and numeric chat_ids: passed through unchanged
     * - phone numbers: strip everything except leading '+' and digits,
     *   so "+998 90 123 45 67" or "+998-90-123-45-67" both become
     *   "+998901234567"
     */
    private function normaliseDestination(string $to): string
    {
        $trimmed = trim($to);

        if ($trimmed === '') {
            return '';
        }

        // @username — keep as-is
        if (str_starts_with($trimmed, '@')) {
            return $trimmed;
        }

        // Numeric chat_id — keep as-is
        if (ctype_digit($trimmed)) {
            return $trimmed;
        }

        // Phone — strip formatting, preserve leading +
        $plus  = str_starts_with($trimmed, '+') ? '+' : '';
        $digits = preg_replace('/\D/', '', $trimmed);

        if ($digits === null || $digits === '') {
            return '';
        }

        return $plus . $digits;
    }

    public function health(): bool
    {
        $url = (string) config('services.tg_direct.url', 'http://127.0.0.1:8766');

        try {
            $response = Http::timeout(3)
                ->connectTimeout(2)
                ->get(rtrim($url, '/') . '/health');

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }
}
