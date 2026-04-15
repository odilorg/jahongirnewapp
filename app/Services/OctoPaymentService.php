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

    public function __construct()
    {
        $this->shopId = config('services.octo.shop_id');
        $this->secret = config('services.octo.secret');
        $this->apiUrl = config('services.octo.url');
        $this->tspId  = config('services.octo.tsp_id'); // optional
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

        Log::info('Octo Payment Request Payload', $payload);

        $response = Http::post($this->apiUrl, $payload);

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

        $response = Http::post($this->apiUrl, $payload);

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
