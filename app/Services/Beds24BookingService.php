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
            'mobile' => $data['guest_phone'] ?? '',
            'numAdult' => $data['num_adults'] ?? 2,
            'numChild' => $data['num_children'] ?? 0,
            'status' => 'confirmed',
            'notes' => $data['notes'] ?? 'Created via Telegram Bot',
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

            if (!$response->successful()) {
                throw new \Exception('Beds24 API HTTP error: ' . $response->status());
            }

            // Response is an array of booking results
            // For single booking: [{success: true, new: {id: 123, ...}}]
            if (is_array($result) && count($result) > 0) {
                $firstResult = $result[0];
                
                if (isset($firstResult['success']) && $firstResult['success']) {
                    // Extract booking ID from 'new' or 'id' field
                    $bookingId = $firstResult['new']['id'] ?? $firstResult['id'] ?? null;
                    
                    return [
                        'success' => true,
                        'bookingId' => $bookingId,
                        'bookId' => $bookingId, // Alias for compatibility
                        'data' => $firstResult,
                    ];
                }
                
                // Check for errors
                if (isset($firstResult['errors'])) {
                    throw new \Exception('Beds24 API errors: ' . json_encode($firstResult['errors']));
                }
            }

            throw new \Exception('Unexpected API response: ' . json_encode($result));
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
            'status' => 'cancelled',
            'comment' => $reason ? 'Cancelled: ' . $reason : 'Cancelled via Telegram Bot',
        ]];

        Log::info('Beds24 Cancel Booking Request', ['payload' => $payload]);

        $response = Http::withHeaders([
            'token' => $this->token,
            'Content-Type' => 'application/json',
            'accept' => 'application/json',
        ])->timeout(30)->post($this->apiUrl . '/bookings', $payload);

        $result = $response->json();
        
        Log::info('Beds24 Cancel Booking Response', ['response' => $result]);

        return $result;
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
     * Check availability using calendar endpoint
     * Implements the exact algorithm specified:
     * 1. Get calendar data for each property
     * 2. Expand date ranges into byDate map
     * 3. Build nights array [arrival..checkout-1]
     * 4. Calculate minimum availability across all nights
     * 5. Return rooms with quantity info
     */
    public function checkAvailability(string $checkIn, string $checkOut, ?array $propertyIds = null): array
    {
        try {
            // Query both properties by default
            if (empty($propertyIds)) {
                $propertyIds = ['41097', '172793']; // Jahongir Hotel, Jahongir Premium
            }

            // Validate and swap dates if needed
            if ($checkIn > $checkOut) {
                Log::warning('checkAvailability: from > to, swapping dates', [
                    'original_checkIn' => $checkIn,
                    'original_checkOut' => $checkOut
                ]);
                [$checkIn, $checkOut] = [$checkOut, $checkIn];
            }

            $allRoomsData = [];

            // Query each property separately
            foreach ($propertyIds as $propertyId) {
                // For the calendar endpoint, we need to subtract 1 day from checkout for the endDate
                // because we're checking nights, not the checkout day itself
                $checkoutDate = new \DateTimeImmutable($checkOut);
                $endDate = $checkoutDate->modify('-1 day')->format('Y-m-d');

                $params = [
                    'propertyId' => $propertyId,
                    'startDate' => $checkIn,
                    'endDate' => $endDate,
                    'includeNumAvail' => 'true',
                    'includePrices' => 'true',
                ];

                Log::info('Beds24 Inventory Calendar Request', ['params' => $params]);

                $response = Http::withHeaders([
                    'token' => $this->token,
                    'accept' => 'application/json',
                ])->timeout(30)->get($this->apiUrl . '/inventory/rooms/calendar', $params);

                $result = $response->json();

                Log::info('Beds24 Inventory Calendar Response', [
                    'property_id' => $propertyId,
                    'response' => $result
                ]);

                if (!$response->successful() || (isset($result['success']) && !$result['success'])) {
                    Log::warning('Beds24 API error for property ' . $propertyId, ['error' => $result]);
                    continue; // Skip this property but continue with others
                }

                if (isset($result['data']) && is_array($result['data'])) {
                    foreach ($result['data'] as $roomData) {
                        $allRoomsData[] = array_merge($roomData, ['propertyId' => $propertyId]);
                    }
                }
            }

            // Build nights array [arrival..checkout-1]
            $nights = [];
            $current = new \DateTimeImmutable($checkIn);
            $checkoutDt = new \DateTimeImmutable($checkOut);
            while ($current < $checkoutDt) {
                $nights[] = $current->format('Y-m-d');
                $current = $current->modify('+1 day');
            }

            // Process each room
            $availableRooms = [];
            
            foreach ($allRoomsData as $room) {
                $roomId = (string) $room['roomId'];
                $roomName = $room['name'];
                $propertyId = $room['propertyId'];

                // Step 2: Expand ranges into byDate map
                $byDate = [];
                if (isset($room['calendar']) && is_array($room['calendar'])) {
                    foreach ($room['calendar'] as $range) {
                        $from = $range['from'];
                        $to = $range['to'];
                        $numAvail = max(0, $range['numAvail'] ?? 0); // Clamp negatives to 0

                        // Expand the inclusive range
                        $start = new \DateTimeImmutable($from);
                        $end = new \DateTimeImmutable($to);
                        $current = $start;
                        
                        while ($current <= $end) {
                            $dateKey = $current->format('Y-m-d');
                            $byDate[$dateKey] = $numAvail; // Later ranges override earlier ones
                            $current = $current->modify('+1 day');
                        }
                    }
                }

                // Step 4: Calculate minimum availability across all nights
                $minAvail = PHP_INT_MAX;
                foreach ($nights as $night) {
                    $avail = $byDate[$night] ?? 0; // Default to 0 if missing
                    $minAvail = min($minAvail, $avail);
                }

                // If minAvail is still PHP_INT_MAX, set it to 0
                if ($minAvail === PHP_INT_MAX) {
                    $minAvail = 0;
                }

                // Room qualifies if min >= 1
                if ($minAvail >= 1) {
                    $availableRooms[] = [
                        'roomId' => $roomId,
                        'roomName' => $roomName,
                        'propertyId' => $propertyId,
                        'quantity' => $minAvail,
                    ];
                }
            }

            // Step 5: Sort by room name (stable sort)
            usort($availableRooms, function($a, $b) {
                return strcmp($a['roomName'], $b['roomName']);
            });

            return [
                'success' => true,
                'checkIn' => $checkIn,
                'checkOut' => $checkOut,
                'nights' => $nights,
                'availableRooms' => $availableRooms,
            ];

        } catch (\Exception $e) {
            Log::error('Beds24 Availability Check Error', [
                'error' => $e->getMessage(),
                'check_in' => $checkIn,
                'check_out' => $checkOut
            ]);

            return [
                'success' => false,
                'availableRooms' => [],
                'error' => $e->getMessage()
            ];
        }
    }


    /**
     * Create multiple bookings (e.g., for group reservations)
     * When makeGroup is true, all bookings will be linked together
     */
    public function createMultipleBookings(array $bookingsData, bool $makeGroup = false): array
    {
        $payload = [];
        
        foreach ($bookingsData as $data) {
            $booking = [
                'propertyId' => (int) $data['property_id'],
                'roomId' => (int) $data['room_id'],
                'arrival' => $data['check_in'],
                'departure' => $data['check_out'],
                'firstName' => $this->extractFirstName($data['guest_name']),
                'lastName' => $this->extractLastName($data['guest_name']),
                'email' => $data['guest_email'] ?? '',
                'mobile' => $data['guest_phone'] ?? '',
                'numAdult' => $data['num_adults'] ?? 2,
                'numChild' => $data['num_children'] ?? 0,
                'status' => 'confirmed',
                'notes' => $data['notes'] ?? 'Created via Telegram Bot',
            ];
            
            // Add group action if requested
            if ($makeGroup) {
                $booking['actions'] = ['makeGroup' => true];
            }
            
            $payload[] = $booking;
        }

        Log::info('Beds24 Create Multiple Bookings Request', [
            'payload' => $payload,
            'count' => count($payload),
            'makeGroup' => $makeGroup
        ]);

        try {
            $response = Http::withHeaders([
                'token' => $this->token,
                'Content-Type' => 'application/json',
                'accept' => 'application/json',
            ])->timeout(30)->post($this->apiUrl . '/bookings', $payload);

            $result = $response->json();

            Log::info('Beds24 Create Multiple Bookings Response', ['response' => $result]);

            if (!$response->successful() || (isset($result['success']) && !$result['success'])) {
                throw new \Exception('Beds24 API error: ' . json_encode($result));
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('Beds24 Multiple Booking Error', ['error' => $e->getMessage()]);
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
