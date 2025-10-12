<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Beds24BookingService
{
    protected string $apiUrl = 'https://api.beds24.com/v2';
    protected string $token;

    public function __construct()
    {
        $this->token = config('services.beds24.api_v2_token', env('BEDS24_API_V2_TOKEN'));
    }

    /**
     * Create a booking in Beds24
     */
    public function createBooking(array $data): array
    {
        $payload = [[
            'propertyId' => (int) $data['property_id'],
            'roomId' => (int) $data['room_id'],
            'arrival' => $data['check_in'],
            'departure' => $data['check_out'],
            'firstName' => $this->extractFirstName($data['guest_name']),
            'lastName' => $this->extractLastName($data['guest_name']),
            'email' => $data['guest_email'] ?? '',
            'phone' => $data['guest_phone'] ?? '',
            'numAdult' => $data['num_adults'] ?? 2,
            'numChild' => $data['num_children'] ?? 0,
            'price' => $data['price'] ?? null,
            'status' => 1, // Confirmed
            'infoText' => $data['notes'] ?? 'Created via Telegram Bot',
        ]];

        Log::info('Beds24 Create Booking Request', ['payload' => $payload]);

        try {
            $response = Http::withHeaders([
                'token' => $this->token,
                'Content-Type' => 'application/json',
                'accept' => 'application/json',
            ])->timeout(30)->post($this->apiUrl . '/bookings', $payload);

            $result = $response->json();

            Log::info('Beds24 Create Booking Response', ['response' => $result]);

            if (!$response->successful() || (isset($result['success']) && !$result['success'])) {
                throw new \Exception('Beds24 API error: ' . json_encode($result));
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('Beds24 Booking Error', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Get booking details
     */
    public function getBooking(string $bookingId): array
    {
        $response = Http::withHeaders([
            'token' => $this->token,
            'accept' => 'application/json',
        ])->timeout(30)->get($this->apiUrl . '/bookings', [
            'bookId' => $bookingId,
        ]);

        return $response->json();
    }

    /**
     * Cancel booking
     */
    public function cancelBooking(string $bookingId, string $reason = ''): array
    {
        $payload = [[
            'id' => (int) $bookingId,
            'status' => 5, // Cancelled status
            'infoText' => 'Cancelled: ' . $reason,
        ]];

        $response = Http::withHeaders([
            'token' => $this->token,
            'Content-Type' => 'application/json',
            'accept' => 'application/json',
        ])->timeout(30)->post($this->apiUrl . '/bookings', $payload);

        return $response->json();
    }

    /**
     * Modify booking
     */
    public function modifyBooking(string $bookingId, array $changes): array
    {
        $payload = array_merge([
            'id' => (int) $bookingId,
        ], $changes);

        $response = Http::withHeaders([
            'token' => $this->token,
            'Content-Type' => 'application/json',
            'accept' => 'application/json',
        ])->timeout(30)->post($this->apiUrl . '/bookings', [$payload]);

        return $response->json();
    }

    /**
     * Check availability by getting existing bookings for the date range
     * Returns array of booked room IDs
     */
    public function checkAvailability(string $checkIn, string $checkOut, ?array $roomIds = null): array
    {
        try {
            $params = [
                'arrival' => $checkIn,
                'departure' => $checkOut,
            ];

            if ($roomIds) {
                $params['roomId'] = implode(',', $roomIds);
            }

            Log::info('Beds24 Check Availability Request', ['params' => $params]);

            // Get existing bookings for these dates
            $response = Http::withHeaders([
                'token' => $this->token,
                'accept' => 'application/json',
            ])->timeout(30)->get($this->apiUrl . '/bookings', $params);

            $result = $response->json();

            Log::info('Beds24 Check Availability Response', ['response' => $result]);

            if (!$response->successful()) {
                throw new \Exception('Beds24 API error: ' . json_encode($result));
            }

            // Extract booked room IDs
            $bookedRoomIds = [];
            if (isset($result['data']) && is_array($result['data'])) {
                foreach ($result['data'] as $booking) {
                    if (isset($booking['roomId'])) {
                        $bookedRoomIds[] = (string) $booking['roomId'];
                    }
                }
            }

            return [
                'success' => true,
                'bookedRoomIds' => array_unique($bookedRoomIds),
                'totalBookings' => $result['count'] ?? 0
            ];

        } catch (\Exception $e) {
            Log::error('Beds24 Availability Check Error', [
                'error' => $e->getMessage(),
                'check_in' => $checkIn,
                'check_out' => $checkOut
            ]);
            throw $e;
        }
    }

    /**
     * Extract first name from full name
     */
    protected function extractFirstName(string $fullName): string
    {
        $parts = explode(' ', trim($fullName), 2);
        return $parts[0] ?? $fullName;
    }

    /**
     * Extract last name from full name
     */
    protected function extractLastName(string $fullName): string
    {
        $parts = explode(' ', trim($fullName), 2);
        return $parts[1] ?? $parts[0];
    }
}
