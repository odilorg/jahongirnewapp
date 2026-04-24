<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Models\Booking;
use App\Models\BookingInquiry;

class OctoPaymentService
{
    protected string $shopId;
    protected string $secret;
    protected string $apiUrl;
    protected ?string $tspId;
    protected ?string $relayUrl;
    protected ?string $relaySecret;

    // Tight timeout on the direct path — when Uzbek ISP routing drops,
    // cURL hangs the full default 30s per retry. 10s is enough for a
    // healthy direct call and bounds the fallback switch cost.
    private const DIRECT_TIMEOUT_SECONDS = 10;
    private const RELAY_TIMEOUT_SECONDS  = 15;

    public function __construct()
    {
        $this->shopId      = config('services.octo.shop_id');
        $this->secret      = config('services.octo.secret');
        $this->apiUrl      = config('services.octo.url');
        $this->tspId       = config('services.octo.tsp_id'); // optional
        $this->relayUrl    = config('services.octo.relay_url');    // optional
        $this->relaySecret = config('services.octo.relay_secret'); // optional
    }

    /**
     * POST to Octo with a try-direct-then-relay fallback.
     *
     * Direct path hits secure.octo.uz. When that fails (timeout, connection
     * refused, 5xx — any non-2xx), we retry via the CF-proxied relay on
     * vps-main. The relay fronts the SAME upstream endpoint, so a 200 from
     * either path is semantically identical to the caller.
     *
     * Relay is skipped if not configured (missing env vars) — behavior then
     * is identical to pre-fallback code.
     *
     * @param  string  $logContext  Short tag for log correlation ("booking_12" / "inquiry_5").
     */
    private function postToOcto(array $payload, string $logContext): \Illuminate\Http\Client\Response
    {
        $directException = null;
        $response        = null;

        try {
            $response = Http::timeout(self::DIRECT_TIMEOUT_SECONDS)
                ->post($this->apiUrl, $payload);

            if ($response->successful()) {
                return $response;
            }

            Log::warning('Octo direct call returned non-2xx, trying relay', [
                'context' => $logContext,
                'status'  => $response->status(),
                'body'    => mb_substr($response->body(), 0, 300),
            ]);
        } catch (\Throwable $e) {
            $directException = $e;
            Log::warning('Octo direct call threw, trying relay', [
                'context' => $logContext,
                'error'   => $e->getMessage(),
            ]);
        }

        // Relay not configured — surface the direct failure as before so
        // callers see identical behavior to pre-fallback code.
        if (! $this->relayUrl || ! $this->relaySecret) {
            if ($directException) {
                throw $directException;
            }

            return $response;
        }

        try {
            $relayResponse = Http::timeout(self::RELAY_TIMEOUT_SECONDS)
                ->withHeaders(['X-Relay-Secret' => $this->relaySecret])
                ->post($this->relayUrl, $payload);
        } catch (\Throwable $relayException) {
            Log::error('Octo both direct and relay failed', [
                'context'        => $logContext,
                'direct_error'   => $directException?->getMessage(),
                'direct_status'  => $response?->status(),
                'relay_error'    => $relayException->getMessage(),
            ]);

            throw new \RuntimeException(
                'Octo unreachable: direct + relay both failed. ' .
                'direct=' . ($directException?->getMessage() ?? 'status ' . ($response?->status() ?? 'n/a')) . '; ' .
                'relay=' . $relayException->getMessage(),
                0,
                $relayException,
            );
        }

        Log::info('Octo relay call result', [
            'context' => $logContext,
            'status'  => $relayResponse->status(),
        ]);

        // If relay ALSO returned non-2xx, this still bubbles up to the
        // caller's `$response->successful()` check which throws. Log an
        // explicit error for the both-fail case so ops sees it separately
        // from "direct failed + relay saved us" events.
        if (! $relayResponse->successful()) {
            Log::error('Octo both direct and relay returned non-2xx', [
                'context'       => $logContext,
                'direct_status' => $response?->status(),
                'relay_status'  => $relayResponse->status(),
                'relay_body'    => mb_substr($relayResponse->body(), 0, 300),
            ]);
        }

        return $relayResponse;
    }

    /**
     * Fetch USD→UZS rate with 5-layer fallback:
     *   1. CBU Uzbekistan (official, primary)
     *   2. open.er-api.com (no key, 1500 req/month free)
     *   3. fawazahmed0 via jsDelivr CDN (no key, unlimited, open source)
     *   4. Last cached rate from any previously successful source
     *   5. OCTO_FALLBACK_USD_UZS_RATE from .env (manual safety net)
     */
    private function getExchangeRate(): float
    {
        $cacheKey = 'usd_uzs_rate';

        // Try each live source in order, return immediately on success
        $rate = $this->fetchFromCbu()
            ?? $this->fetchFromOpenErApi()
            ?? $this->fetchFromFawazahmed();

        if ($rate !== null) {
            Cache::put($cacheKey, $rate, now()->addHours(6));
            return $rate;
        }

        // Layer 4: last cached rate (from any prior successful fetch)
        if (Cache::has($cacheKey)) {
            $cached = (float) Cache::get($cacheKey);
            Log::warning('All live exchange rate sources failed, using cached rate', ['rate' => $cached]);
            return $cached;
        }

        // Layer 5: manual fallback from .env
        $fallback = (float) config('services.octo.fallback_usd_uzs_rate');
        Log::warning('All exchange rate sources failed, using .env fallback — update OCTO_FALLBACK_USD_UZS_RATE if stale', [
            'rate' => $fallback,
        ]);
        return $fallback;
    }

    // Layer 1: Central Bank of Uzbekistan
    private function fetchFromCbu(): ?float
    {
        try {
            $response = Http::timeout(8)->get(
                'https://cbu.uz/ru/arkhiv-kursov-valyut/json/USD/' . now()->format('Y-m-d') . '/'
            );
            if ($response->successful()) {
                $data = $response->json();
                if (isset($data[0]['Rate'])) {
                    $rate = (float) $data[0]['Rate'];
                    Log::info('Exchange rate fetched from CBU', ['rate' => $rate]);
                    return $rate;
                }
            }
        } catch (\Exception $e) {
            Log::warning('CBU exchange rate source failed', ['error' => $e->getMessage()]);
        }
        return null;
    }

    // Layer 2: open.er-api.com — no API key, 1500 req/month free
    private function fetchFromOpenErApi(): ?float
    {
        try {
            $response = Http::timeout(8)->get('https://open.er-api.com/v6/latest/USD');
            if ($response->successful()) {
                $data = $response->json();
                if (isset($data['rates']['UZS'])) {
                    $rate = (float) $data['rates']['UZS'];
                    Log::info('Exchange rate fetched from open.er-api.com', ['rate' => $rate]);
                    return $rate;
                }
            }
        } catch (\Exception $e) {
            Log::warning('open.er-api.com exchange rate source failed', ['error' => $e->getMessage()]);
        }
        return null;
    }

    // Layer 3: fawazahmed0 via jsDelivr CDN — no key, unlimited, open source
    private function fetchFromFawazahmed(): ?float
    {
        // Two URLs: primary CDN + official fallback
        $urls = [
            'https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/usd.json',
            'https://latest.currency-api.pages.dev/v1/currencies/usd.json',
        ];

        foreach ($urls as $url) {
            try {
                $response = Http::timeout(8)->get($url);
                if ($response->successful()) {
                    $data = $response->json();
                    if (isset($data['usd']['uzs'])) {
                        $rate = (float) $data['usd']['uzs'];
                        Log::info('Exchange rate fetched from fawazahmed0 currency API', ['rate' => $rate, 'url' => $url]);
                        return $rate;
                    }
                }
            } catch (\Exception $e) {
                Log::warning('fawazahmed0 exchange rate source failed', ['url' => $url, 'error' => $e->getMessage()]);
            }
        }
        return null;
    }

    /**
     * Create an Octo one-stage payment link.
     *
     * @param Booking $booking
     * @param float   $usdAmount  The amount in USD
     * @return string  The payment URL
     *
     * @throws \Exception If the request fails
     */
    public function createPaymentLink(Booking $booking, float $usdAmount): string
    {
        $exchangeRate = $this->getExchangeRate();
        $uzsAmount = round($usdAmount * $exchangeRate);

        $transactionId = 'booking_' . $booking->id . '_' . Str::random(6);

        $guest      = $booking->guest;
        $guestName  = $guest ? ($guest->full_name ?? '') : '';
        $guestPhone = $guest ? ($guest->phone ?? '') : '';
        $guestEmail = $guest ? ($guest->email ?? '') : '';

        $payload = [
            'octo_shop_id'        => (int) $this->shopId,
            'octo_secret'         => $this->secret,
            'shop_transaction_id' => $transactionId,
            'auto_capture'        => true,
            'init_time'           => now()->format('Y-m-d H:i:s'),
            'test'                => false,
            'user_data'           => [
                'user_id' => $guestName,
                'phone'   => $guestPhone,
                'email'   => $guestEmail,
            ],
            'total_sum'   => $uzsAmount,
            'currency'    => 'UZS',
            'description' => "Booking #{$booking->id} Payment",
            'basket'      => [
                [
                    'position_desc' => "Booking #{$booking->id}",
                    'count'         => 1,
                    'price'         => $uzsAmount,
                    'spic'          => 'N/A',
                ],
            ],
            'payment_methods' => [
                [
                    'method' => 'bank_card',
                ],
            ],
            'tsp_id'      => $this->tspId ? (int) $this->tspId : null,
            'return_url'  => url('/payment/success'),
            'notify_url'  => route('octo.callback'),
            'language'    => 'en',
            'ttl'         => 5000,
        ];

        if (!$payload['tsp_id']) {
            unset($payload['tsp_id']);
        }

        // Redact the shop secret before logging — logs get shipped, read,
        // and cached. Matches the inquiry-path convention (line ~346) where
        // only non-credential fields are logged.
        $sanitizedPayload = $payload;
        if (isset($sanitizedPayload['octo_secret'])) {
            $sanitizedPayload['octo_secret'] = '***redacted***';
        }
        Log::info('Octo Payment Request Payload', $sanitizedPayload);

        $response = $this->postToOcto($payload, 'booking_' . $booking->id);

        Log::info('Octo Payment Response', [
            'status' => $response->status(),
            'body'   => $response->body(),
        ]);

        if (!$response->successful()) {
            throw new \Exception('Octo payment link creation failed: ' . $response->body());
        }

        $json = $response->json();
        if (!isset($json['data']['octo_pay_url'])) {
            throw new \Exception('No "octo_pay_url" in Octo response: ' . $response->body());
        }

        return $json['data']['octo_pay_url'];
    }

    /**
     * Create an Octo one-stage payment link for a website booking inquiry.
     *
     * Parallel to createPaymentLink() but decoupled from the legacy Booking /
     * Guest / tours schema. Uses `inquiry_{id}_{random}` as the transaction
     * prefix so OctoCallbackController can route the webhook to the inquiry
     * pipeline instead of the legacy booking pipeline.
     *
     * @return array{url: string, transaction_id: string, uzs_amount: int}
     *   The payment URL to send to the customer, the shop_transaction_id
     *   persisted on the inquiry for webhook lookup, and the UZS amount
     *   actually charged (for the operator's records).
     *
     * @throws \Exception If the Octo request fails or the response is malformed.
     */
    public function createPaymentLinkForInquiry(BookingInquiry $inquiry, float $usdAmount): array
    {
        $exchangeRate = $this->getExchangeRate();
        $uzsAmount    = (int) round($usdAmount * $exchangeRate);

        $transactionId = 'inquiry_' . $inquiry->id . '_' . Str::random(6);

        $description = "Jahongir Travel {$inquiry->reference} — "
            . mb_strimwidth((string) $inquiry->tour_name_snapshot, 0, 120, '…');

        $payload = [
            'octo_shop_id'        => (int) $this->shopId,
            'octo_secret'         => $this->secret,
            'shop_transaction_id' => $transactionId,
            'auto_capture'        => true,
            'init_time'           => now()->format('Y-m-d H:i:s'),
            'test'                => false,
            'user_data'           => [
                // Embed reference + human context so Octo's dashboard / logs
                // show something recognisable when reconciling payments.
                'user_id' => $inquiry->reference . ' / ' . $inquiry->customer_name,
                'phone'   => (string) $inquiry->customer_phone,
                'email'   => (string) $inquiry->customer_email,
            ],
            'total_sum'   => $uzsAmount,
            'currency'    => 'UZS',
            'description' => $description,
            'basket'      => [
                [
                    'position_desc' => $description,
                    'count'         => 1,
                    'price'         => $uzsAmount,
                    'spic'          => 'N/A',
                ],
            ],
            'payment_methods' => [
                ['method' => 'bank_card'],
            ],
            'tsp_id'     => $this->tspId ? (int) $this->tspId : null,
            'return_url' => url('/payment/success'),
            'notify_url' => route('octo.callback'),
            'language'   => 'en',
            'ttl'        => 5000,
        ];

        if (! $payload['tsp_id']) {
            unset($payload['tsp_id']);
        }

        Log::info('Octo Payment Request Payload (inquiry)', [
            'reference'     => $inquiry->reference,
            'transaction'   => $transactionId,
            'usd'           => $usdAmount,
            'uzs'           => $uzsAmount,
            'exchange_rate' => $exchangeRate,
        ]);

        $response = $this->postToOcto($payload, 'inquiry_' . $inquiry->id);

        Log::info('Octo Payment Response (inquiry)', [
            'reference' => $inquiry->reference,
            'status'    => $response->status(),
            'body'      => $response->body(),
        ]);

        if (! $response->successful()) {
            throw new \Exception('Octo payment link creation failed: ' . $response->body());
        }

        $json = $response->json();
        if (! isset($json['data']['octo_pay_url'])) {
            throw new \Exception('No "octo_pay_url" in Octo response: ' . $response->body());
        }

        return [
            'url'            => $json['data']['octo_pay_url'],
            'transaction_id' => $transactionId,
            'uzs_amount'     => $uzsAmount,
        ];
    }
}
