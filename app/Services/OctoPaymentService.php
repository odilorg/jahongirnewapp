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
     * Create an Octo one-stage payment link (JSON-based, no Basic Auth).
     *
     * @param  Booking $booking
     * @param  float   $usdAmount  The amount in USD
     * @return string  The payment URL to redirect the user to
     *
     * Logs the request payload and response for debugging.
     */
    public function createPaymentLink(Booking $booking, float $usdAmount): string
    {
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
            'test'                => true,
            'user_data'           => [
                'user_id' => $guestName,
                'phone'   => $guestPhone,
                'email'   => $guestEmail,
            ],
            'total_sum'   => $usdAmount,
            'currency'    => 'UZS',  // or change to 'UZS' if required
            'description' => "Booking #{$booking->id} Payment",
            'basket'      => [
                [
                    'position_desc' => "Booking #{$booking->id}",
                    'count'         => 1,
                    'price'         => $usdAmount,
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

        // Remove tsp_id if null.
        if (!$payload['tsp_id']) {
            unset($payload['tsp_id']);
        }

        // Log the outgoing request payload.
        Log::info('Octo Payment Request Payload', $payload);

        $response = Http::post($this->apiUrl, $payload);

        // Log the response from Octo.
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
