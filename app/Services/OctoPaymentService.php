<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
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
        // Load from config/services.php
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
     * The doc's example used "currency": "UZS". We'll set "currency" => "USD" (if Octo supports it).
     * If you need "UZS", pass it as well; the code is the same otherwise.
     */
    public function createPaymentLink(Booking $booking, float $usdAmount): string
    {
        // Construct a "shop_transaction_id" from booking ID, or any unique reference
        $transactionId = 'booking_'.$booking->id.'_'.Str::random(6);

        // Build the "user_data" object from the booking’s guest
        $guest        = $booking->guest;
        $guestName    = $guest ? ($guest->full_name ?? '') : '';
        $guestPhone   = $guest ? ($guest->phone ?? '') : '';
        $guestEmail   = $guest ? ($guest->email ?? '') : '';

        // Prepare the JSON data
        // NOTE: The sample shows "currency": "UZS" => "total_sum": 1000.0
        // If you truly want USD, set "currency": "USD" or confirm Octo's docs. 
        // We'll pass "bank_card" in "payment_methods" to accept Visa/MasterCard.
        // "auto_capture" => true means we want an immediate capture, not an auth+capture separate flow.
        $payload = [
            'octo_shop_id'        => (int) $this->shopId,
            'octo_secret'         => $this->secret,
            'shop_transaction_id' => $transactionId,
            'auto_capture'        => true,
            'init_time'           => now()->format('Y-m-d H:i:s'),
            'test'                => false,    // set true for testing if needed
            'user_data' => [
                'user_id' => $guestName,
                'phone'   => $guestPhone,
                'email'   => $guestEmail,
            ],
            'total_sum'   => $usdAmount,
            'currency'    => 'USD',    // or 'UZS', if that’s what your business supports
            'description' => "Booking #{$booking->id} Payment",
            'basket' => [
                [
                    'position_desc' => "Booking #{$booking->id}",
                    'count'         => 1,
                    'price'         => $usdAmount,
                    'spic'          => 'N/A', // optional additional info
                ],
            ],
            'payment_methods' => [
                [
                    'method' => 'bank_card',
                ],
            ],
            // If you have a TSP ID from Octo:
            'tsp_id'      => $this->tspId ? (int) $this->tspId : null,
            'return_url'  => url('/payment/success'), // or your custom route
            'notify_url'  => route('octo.callback'),  // your server callback
            'language'    => 'en',    // 'uz', 'ru', etc.
            'ttl'         => 15,      // minutes until link expires
        ];

        // If "tsp_id" is null, remove it
        if (!$payload['tsp_id']) {
            unset($payload['tsp_id']);
        }

        // Send POST JSON to Octo
        $response = Http::post($this->apiUrl, $payload);

        if (!$response->successful()) {
            throw new \Exception('Octo payment link creation failed: ' . $response->body());
        }

        $json = $response->json();
        if (!isset($json['payment_url'])) {
            throw new \Exception('No "payment_url" in Octo response: ' . $response->body());
        }

        return $json['payment_url'];
    }
}
