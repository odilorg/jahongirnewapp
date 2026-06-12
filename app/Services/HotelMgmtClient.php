<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin HTTP wrapper around the hotel-mgmt PMS discovery webhook receiver
 * (https://hotel-staging.jahongir-travel.uz/api/pms/beds24/webhook).
 *
 * We own the only Beds24 dashboard webhook URL, so hotel-mgmt gets a copy of
 * each Beds24 payload by us forwarding it. The receiver is idempotent (dedups
 * by SHA-256 payload hash), always returns 200, and does zero accounting —
 * so re-delivery on retry is safe.
 *
 * Deliberately minimal: build the request, return a result array, NEVER throw
 * upward. The caller (ForwardBeds24WebhookToHotelMgmtJob) decides whether a
 * non-ok result should trigger a queue retry.
 */
class HotelMgmtClient
{
    /**
     * Forward a raw Beds24 webhook payload to hotel-mgmt.
     *
     * @param  array<string,mixed>  $payload
     * @return array{ok: bool, status?: int, error?: string}
     */
    public function forwardBeds24Webhook(array $payload): array
    {
        $url = (string) config('services.hotel_mgmt.webhook_url', '');
        $timeout = (int) config('services.hotel_mgmt.timeout', 5);

        if ($url === '') {
            Log::warning('HotelMgmtClient: no webhook_url configured — skipping fan-out');

            return ['ok' => false, 'error' => 'no_url'];
        }

        try {
            $response = Http::timeout($timeout)
                ->connectTimeout(3)
                ->asJson()
                ->post($url, $payload);
        } catch (\Throwable $e) {
            Log::warning('HotelMgmtClient: HTTP exception forwarding Beds24 webhook', [
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'error' => 'http_exception: '.$e->getMessage()];
        }

        if (! $response->successful()) {
            Log::warning('HotelMgmtClient: non-2xx forwarding Beds24 webhook', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 300),
            ]);

            return ['ok' => false, 'status' => $response->status(), 'error' => 'http_'.$response->status()];
        }

        return ['ok' => true, 'status' => $response->status()];
    }
}
