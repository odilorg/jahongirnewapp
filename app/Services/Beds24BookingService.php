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
    public function checkAvailability(string $checkIn, string $checkOut, ?array $propertyIds = null): array
    {
        try {
            // Query both properties by default
            if (empty($propertyIds)) {
                $propertyIds = ['41097', '172793']; // Jahongir Hotel, Jahongir Premium
            }

            $unavailableRoomIds = []; // Room types that are NOT available

            // Query each property separately
            foreach ($propertyIds as $propertyId) {
                $params = [
                    'propertyId' => $propertyId,
                    'startDate' => $checkIn,
                    'endDate' => $checkIn, // Check only the check-in date
                ];

                Log::info('Beds24 Inventory Availability Request', ['params' => $params]);

                $response = Http::withHeaders([
                    'token' => $this->token,
                    'accept' => 'application/json',
                ])->timeout(30)->get($this->apiUrl . '/inventory/rooms/availability', $params);

                $result = $response->json();

                Log::info('Beds24 Inventory Availability Response', [
                    'property_id' => $propertyId,
                    'response' => $result
                ]);

                if (!$response->successful()) {
                    Log::warning('Beds24 API error for property ' . $propertyId, ['error' => $result]);
                    continue; // Skip this property but continue with others
                }

                // Parse availability: empty value means NOT available
                if (isset($result['data']) && is_array($result['data'])) {
                    foreach ($result['data'] as $room) {
                        $roomId = (string) $room['roomId'];
                        $availability = $room['availability'][$checkIn] ?? 0;
                        
                        // If availability is 0 or empty, mark room as unavailable
                        if (empty($availability)) {
                            $unavailableRoomIds[] = $roomId;
                        }
                    }
                }
            }

            return [
                'success' => true,
                'unavailableRoomIds' => array_unique($unavailableRoomIds),
            ];

        } catch (\Exception $e) {
            Log::error('Beds24 Availability Check Error', [
                'error' => $e->getMessage(),
                'check_in' => $checkIn,
                'check_out' => $checkOut
            ]);

            return [
                'success' => false,
                'unavailableRoomIds' => [],
                'error' => $e->getMessage()
            ];
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
