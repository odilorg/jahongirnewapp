<?php

namespace App\Services\Messaging;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppSender
{
    private const MIN_PHONE_LENGTH = 7;
    private const MAX_PHONE_LENGTH = 15;

    /**
     * Normalize phone to digits-only format for wa-api.
     * Returns null if phone is invalid.
     */
    public function normalizePhone(?string $phone): ?string
    {
        if (empty($phone) || $phone === 'not-provided') {
            return null;
        }

        // Strip everything except digits
        $digits = preg_replace('/[^0-9]/', '', $phone);

        if (strlen($digits) < self::MIN_PHONE_LENGTH || strlen($digits) > self::MAX_PHONE_LENGTH) {
            return null;
        }

        return $digits;
    }

    /**
     * Send a WhatsApp message via the local wa-api.
     */
    public function send(string $normalizedPhone, string $message): SendResult
    {
        // Phase 26 — global kill switch. Flip SEND_GUEST_MESSAGES=false in
        // .env to pause ALL outbound guest WhatsApp immediately without
        // touching scheduler or code. Useful during testing, maintenance,
        // or incident response. Returns a non-retryable fail so callers
        // log 'blocked' rather than retry forever.
        if (! (bool) config('app.send_guest_messages', true)) {
            \Illuminate\Support\Facades\Log::info('WhatsAppSender: send blocked by kill switch', [
                'to'      => $normalizedPhone,
                'preview' => mb_substr($message, 0, 80),
            ]);
            return SendResult::fail('whatsapp', 'send_guest_messages=false (global kill switch)', retryable: false);
        }

        $url = config('services.gyg.wa_api_url', 'http://127.0.0.1:8080/api/send');

        try {
            $response = Http::timeout(15)->post($url, [
                'recipient' => $normalizedPhone,
                'message'   => $message,
            ]);

            $body = $response->json();

            if ($response->successful() && ($body['success'] ?? false)) {
                return SendResult::ok('whatsapp');
            }

            $error = $body['message'] ?? $body['error'] ?? 'unknown wa-api error';

            // Classify retryable vs permanent
            $retryable = str_contains(strtolower($error), 'timeout')
                || str_contains(strtolower($error), 'connection')
                || $response->status() >= 500;

            return SendResult::fail('whatsapp', $error, $retryable);
        } catch (\Throwable $e) {
            return SendResult::fail('whatsapp', $e->getMessage(), retryable: true);
        }
    }
}
