<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Models\Booking;

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
     * Fetch the current exchange rate for USD to UZS.
     *
     * @return float The exchange rate
     * @throws \Exception If unable to fetch the exchange rate
     */
    private function getExchangeRate(): float
    {
        $date = now()->format('Y-m-d');
        $response = Http::get("https://cbu.uz/ru/arkhiv-kursov-valyut/json/USD/{$date}/");

        if (!$response->successful()) {
            throw new \Exception('Failed to fetch exchange rate from CBU: ' . $response->body());
        }

        $data = $response->json();

        if (!isset($data[0]['Rate'])) {
            throw new \Exception('Exchange rate data is missing in CBU response');
        }

        return (float) $data[0]['Rate'];
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
            'ttl'         => 15,
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
}
