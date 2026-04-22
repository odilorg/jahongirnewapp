<?php

namespace App\Services;

use App\Support\BookingBot\LogSanitizer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

class Beds24BookingService
{
    protected string $apiUrl = 'https://api.beds24.com/v2';
    protected ?string $token = null;
    protected string $refreshToken;

    private const CACHE_KEY = 'beds24_access_token';
    private const CACHE_KEY_FALLBACK = 'beds24_access_token_fallback';
    private const CACHE_KEY_LAST_REFRESH = 'beds24_last_refresh_time';
    private const CACHE_KEY_LAST_ERROR = 'beds24_last_refresh_error';
    private const CACHE_KEY_REFRESH_TOKEN = 'beds24_rotated_refresh_token';
    private const MAX_RETRY_ATTEMPTS = 3;
    private const RETRY_DELAYS_MS = [200, 1000, 3000]; // Exponential backoff

    public function __construct()
    {
        // Prefer cached rotated refresh token (Beds24 rotates on each use).
        // Cast to string: config() returns null when the env var is absent
        // (key exists but is null), which cannot be assigned to a typed string.
        $this->refreshToken = (string)(Cache::get(self::CACHE_KEY_REFRESH_TOKEN)
            ?: config('services.beds24.api_v2_refresh_token', ''));
    }

    /**
     * Get the API token, refreshing if needed. Lazy-loaded to avoid constructor crashes.
     */
    protected function getToken(): string
    {
        if ($this->token === null) {
            $this->token = $this->getValidToken();
        }
        return $this->token;
    }

    /**
     * Get a valid access token with retry logic and fallback.
     */
    protected function getValidToken(): string
    {
        // 1. Try primary cache
        $cached = Cache::get(self::CACHE_KEY);
        if ($cached) {
            return $cached;
        }

        // 2. Try to refresh with retries
        $lastException = null;
        for ($attempt = 1; $attempt <= self::MAX_RETRY_ATTEMPTS; $attempt++) {
            try {
                $token = $this->attemptTokenRefresh($attempt);
                if ($token) {
                    // Clear any previous error state
                    Cache::forget(self::CACHE_KEY_LAST_ERROR);
                    return $token;
                }
            } catch (\Throwable $e) {
                $lastException = $e;
                Log::warning("Beds24 token refresh attempt {$attempt}/" . self::MAX_RETRY_ATTEMPTS . " failed", [
                    'error' => $e->getMessage(),
                ]);
                if ($attempt < self::MAX_RETRY_ATTEMPTS) {
                    usleep(self::RETRY_DELAYS_MS[$attempt - 1] * 1000);
                }
            }
        }

        // 3. All retries failed — try fallback token (cached from last successful refresh)
        $fallback = Cache::get(self::CACHE_KEY_FALLBACK);
        if ($fallback) {
            Log::warning('Beds24: Using fallback token after all refresh attempts failed');
            return $fallback;
        }

        // 4. Total failure — alert owner and throw
        $errorMsg = $lastException ? $lastException->getMessage() : 'Unknown error';
        Cache::put(self::CACHE_KEY_LAST_ERROR, [
            'message' => $errorMsg,
            'at' => now()->toIso8601String(),
            'attempts' => self::MAX_RETRY_ATTEMPTS,
        ], now()->addHours(24));

        $this->alertOwnerTokenFailure($errorMsg);

        Log::critical('Beds24: All token refresh attempts failed, no fallback available', [
            'error' => $errorMsg,
            'attempts' => self::MAX_RETRY_ATTEMPTS,
        ]);

        throw new \RuntimeException('Beds24 token refresh failed after ' . self::MAX_RETRY_ATTEMPTS . ' attempts: ' . $errorMsg);
    }

    /**
     * Single token refresh attempt.
     */
    private function attemptTokenRefresh(int $attempt): ?string
    {
        if (empty($this->refreshToken)) {
            throw new \RuntimeException('BEDS24_API_V2_REFRESH_TOKEN is not configured');
        }

        $response = Http::withHeaders([
            'refreshToken' => $this->refreshToken,
            'accept' => 'application/json',
        ])->timeout(10)->get('https://beds24.com/api/v2/authentication/token');

        $result = $response->json();

        if (!$response->successful()) {
            throw new \RuntimeException('HTTP ' . $response->status() . ': ' . json_encode($result));
        }

        if (isset($result['token'])) {
            $token = $result['token'];
            $expiresIn = $result['expiresIn'] ?? 86400;

            // Cache primary token (expires 5 min early)
            Cache::put(self::CACHE_KEY, $token, now()->addSeconds($expiresIn - 300));

            // Cache fallback token (longer TTL - survives primary cache expiry)
            Cache::put(self::CACHE_KEY_FALLBACK, $token, now()->addSeconds($expiresIn + 7200));

            // Track last successful refresh
            Cache::put(self::CACHE_KEY_LAST_REFRESH, now()->toIso8601String(), now()->addDays(7));

            // Persist rotated refresh token (Beds24 rotates on each use)
            if (isset($result['refreshToken'])) {
                $rotatedToken = $result['refreshToken'];
                Cache::put(self::CACHE_KEY_REFRESH_TOKEN, $rotatedToken, now()->addDays(60));
                $this->refreshToken = $rotatedToken;
                // Write back to .env so Redis loss never leaves us with a stale fallback
                $this->persistRefreshTokenToEnv($rotatedToken);
                Log::info('Beds24 refresh token rotated and cached');
            }

            Log::info('Beds24 token refreshed', [
                'attempt' => $attempt,
                'expires_in' => $expiresIn,
                'next_refresh' => now()->addSeconds($expiresIn - 300)->toIso8601String(),
            ]);

            return $token;
        }

        throw new \RuntimeException('No token in response: ' . json_encode($result));
    }

    /**
     * Force a token refresh (for scheduled tasks / manual refresh).
     */
    public function forceRefresh(): array
    {
        Cache::forget(self::CACHE_KEY);
        $this->token = null;

        try {
            $token = $this->getValidToken();
            return [
                'success' => true,
                'message' => 'Token refreshed successfully',
                'last_refresh' => Cache::get(self::CACHE_KEY_LAST_REFRESH),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'last_error' => Cache::get(self::CACHE_KEY_LAST_ERROR),
            ];
        }
    }

    /**
     * Get token system health status.
     */
    public function getTokenStatus(): array
    {
        $hasPrimary = Cache::has(self::CACHE_KEY);
        $hasFallback = Cache::has(self::CACHE_KEY_FALLBACK);
        $lastRefresh = Cache::get(self::CACHE_KEY_LAST_REFRESH);
        $lastError = Cache::get(self::CACHE_KEY_LAST_ERROR);

        $status = 'unknown';
        if ($hasPrimary) {
            $status = 'healthy';
        } elseif ($hasFallback) {
            $status = 'degraded';
        } elseif ($lastError) {
            $status = 'critical';
        }

        return [
            'status' => $status,
            'has_primary_token' => $hasPrimary,
            'has_fallback_token' => $hasFallback,
            'last_refresh' => $lastRefresh,
            'last_error' => $lastError,
            'has_rotated_refresh_token' => Cache::has(self::CACHE_KEY_REFRESH_TOKEN),
            'refresh_token_configured' => !empty($this->refreshToken),
            'refresh_token_preview' => !empty($this->refreshToken)
                ? substr($this->refreshToken, 0, 6) . '...' . substr($this->refreshToken, -4)
                : 'NOT SET',
        ];
    }

    /**
     * Alert owner via Telegram when token refresh fails.
     * Only alerts once per hour to avoid spam.
     */
    private function alertOwnerTokenFailure(string $error): void
    {
        $throttleKey = 'beds24_token_alert_sent';
        if (Cache::has($throttleKey)) {
            return; // Already alerted recently
        }

        try {
            $chatId = config('services.owner_alert_bot.owner_chat_id');
            if (!$chatId) {
                return;
            }

            $message = "🚨 *Beds24 Token Error*\n\n"
                . "Token refresh failed after " . self::MAX_RETRY_ATTEMPTS . " attempts.\n\n"
                . "*Error:* `" . substr($error, 0, 200) . "`\n\n"
                . "⚠️ *Impact:* Booking creation, availability checks, and guest info enrichment are DOWN.\n\n"
                . "🔧 *Fix:* Go to Beds24 dashboard → API → copy valid refresh token → update `.env` `BEDS24_API_V2_REFRESH_TOKEN`\n\n"
                . "Then run: `php artisan beds24:refresh-token`";

            $resolver = app(\App\Contracts\Telegram\BotResolverInterface::class);
            $transport = app(\App\Contracts\Telegram\TelegramTransportInterface::class);
            $bot = $resolver->resolve('owner-alert');
            $transport->sendMessage($bot, $chatId, $message, ['parse_mode' => 'Markdown']);

            Cache::put($throttleKey, true, now()->addHour());
        } catch (\Throwable $e) {
            Log::error('Failed to send Beds24 token alert to owner', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Make an API call with automatic token retry on 401.
     */
    public function apiCall(string $method, string $endpoint, array $data = [], array $query = []): \Illuminate\Http\Client\Response
    {
        $request = Http::withHeaders([
            'token' => $this->getToken(),
            'Content-Type' => 'application/json',
            'accept' => 'application/json',
        ])->timeout(30);

        $response = match (strtoupper($method)) {
            'GET' => $request->get($this->apiUrl . $endpoint, $query ?: $data),
            'POST' => $request->post($this->apiUrl . $endpoint, $data),
            default => throw new \InvalidArgumentException("Unsupported method: $method"),
        };

        // If 401, token might be stale — force refresh and retry once
        if ($response->status() === 401) {
            Log::info('Beds24 API returned 401, forcing token refresh and retrying');
            Cache::forget(self::CACHE_KEY);
            $this->token = null;

            $request = Http::withHeaders([
                'token' => $this->getToken(),
                'Content-Type' => 'application/json',
                'accept' => 'application/json',
            ])->timeout(30);

            $response = match (strtoupper($method)) {
                'GET' => $request->get($this->apiUrl . $endpoint, $query ?: $data),
                'POST' => $request->post($this->apiUrl . $endpoint, $data),
            };
        }

        return $response;
    }

    /**
     * Create a booking in Beds24 from the legacy flat-array shape.
     *
     * Kept for existing callers. Internally delegates to
     * createBookingFromPayload() so the HTTP/response-parsing code path
     * has exactly one implementation.
     */
    public function createBooking(array $data): array
    {
        $payload = [
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

        return $this->createBookingFromPayload($payload);
    }

    /**
     * Create a booking from an already-prepared Beds24 single-booking
     * payload. Beds24 expects the booking object wrapped in an array;
     * we wrap here so callers pass a plain associative array.
     *
     * Used by the hotel-booking-bot flow, where the payload (including
     * optional invoiceItems) is built by BuildBeds24BookingPayloadAction.
     * Keeps this adapter free of booking-business rules.
     */
    public function createBookingFromPayload(array $payload): array
    {
        $requestBody = [$payload];

        Log::info('Beds24 Create Booking Request', LogSanitizer::context(['payload' => $requestBody]));

        try {
            $response = $this->apiCall('POST', '/bookings', $requestBody);

            $result = $response->json();

            Log::info('Beds24 Create Booking Response', LogSanitizer::context(['response' => $result]));

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
                        'id' => $bookingId, // Alias for compatibility
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
     * Create multiple Beds24 bookings from already-prepared payloads and
     * (optionally) link them as a group.
     *
     * Contract (verified against Beds24 v2 on 2026-04-22 probe):
     *   - Input: list of single-booking payloads matching the shape produced
     *     by BuildBeds24BookingPayloadAction (propertyId, roomId, dates,
     *     guest, status, notes, optional invoiceItems).
     *   - When $makeGroup is true, each payload is tagged with
     *     actions.makeGroup=true before POST.
     *   - Beds24 returns an array of per-booking results in the SAME order
     *     as the input. First element is the master (new.id, no masterId);
     *     subsequent elements are siblings (new.id + new.masterId).
     *
     * This method does NOT roll back on partial failure — the caller owns
     * atomicity (Phase 7 Rule 3 — capture created ids, cancel on any
     * failure). That keeps the adapter business-rule-free per principle 7.
     */
    public function createMultipleBookingsFromPayloads(array $payloads, bool $makeGroup = true): array
    {
        if ($makeGroup) {
            foreach ($payloads as &$payload) {
                $payload['actions'] = array_merge(
                    $payload['actions'] ?? [],
                    ['makeGroup' => true],
                );
            }
            unset($payload);
        }

        Log::info('Beds24 Create Group Booking Request', LogSanitizer::context([
            'count'     => count($payloads),
            'makeGroup' => $makeGroup,
            'payload'   => $payloads,
        ]));

        $response = $this->apiCall('POST', '/bookings', $payloads);

        $result = $response->json();

        Log::info('Beds24 Create Group Booking Response', LogSanitizer::context(['response' => $result]));

        if (!$response->successful()) {
            throw new \Exception('Beds24 API HTTP error: ' . $response->status());
        }

        if (!is_array($result)) {
            throw new \Exception('Unexpected Beds24 response: ' . json_encode($result));
        }

        return $result;
    }

    /**
     * Get booking details
     */
    public function getBooking(string $bookingId): array
    {
        $response = $this->apiCall('GET', '/bookings', ['id' => $bookingId]);

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

        Log::info('Beds24 Cancel Booking Request', LogSanitizer::context(['payload' => $payload]));

        $response = $this->apiCall('POST', '/bookings', $payload);

        $result = $response->json();
        
        Log::info('Beds24 Cancel Booking Response', LogSanitizer::context(['response' => $result]));

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

        $response = $this->apiCall('POST', '/bookings', [$payload]);

        return $response->json();
    }

    /**
     * Write payment option amounts to a booking's infoItems so they appear
     * on the printed registration form via template variables like
     * [BOOKINGINFOCODETEXT:UZS_AMOUNT].
     *
     * Beds24 API v2: POST /bookings with infoItems merges by code — existing
     * values for the same code are overwritten.
     *
     * @param  int   $bookingId Beds24 booking ID
     * @param  array $items     Associative array: code → value string
     *                          e.g. ['UZS_AMOUNT' => '490 000', 'EUR_RATE' => '13 400 (CBU 13 600 - 200)']
     * @throws \RuntimeException on API failure
     */
    public function writePaymentOptionsToInfoItems(int $bookingId, array $items): void
    {
        $infoItems = [];
        foreach ($items as $code => $value) {
            $infoItems[] = [
                'code'  => $code,
                'text'  => (string) $value,
            ];
        }

        $payload = [[
            'id'        => $bookingId,
            'infoItems' => $infoItems,
        ]];

        Log::info("Beds24: writing {$bookingId} infoItems", [
            'booking_id' => $bookingId,
            'codes'      => array_keys($items),
        ]);

        $response = $this->apiCall('POST', '/bookings', $payload);

        if ($response->status() === 429) {
            throw new \App\Exceptions\Beds24RateLimitException(
                "Beds24 rate limit (HTTP 429) for booking {$bookingId} — FxSyncJob will retry with backoff"
            );
        }

        if (!$response->successful()) {
            throw new \RuntimeException(
                "Beds24 writePaymentOptionsToInfoItems failed for booking {$bookingId}: HTTP {$response->status()}"
            );
        }

        $result = $response->json();

        // Beds24 returns [{success: true|false, ...}] — surface any errors
        $first = $result[0] ?? [];
        if (isset($first['errors']) && count($first['errors']) > 0) {
            throw new \RuntimeException(
                "Beds24 infoItems write returned errors for booking {$bookingId}: " . json_encode($first['errors'])
            );
        }

        Log::info("Beds24: infoItems written successfully for booking {$bookingId}");
    }

    /**
     * Get bookings list with filters
     *
     * @param array $filters Available filters:
     *   - filter: 'arrivals', 'departures', 'current', 'new'
     *   - propertyId: array or string
     *   - arrival: YYYY-MM-DD
     *   - arrivalFrom/To, departureFrom/To
     *   - status: array of statuses
     *   - searchString: search in guest name, email
     * @return array
     */
    public function getBookings(array $filters = []): array
    {
        try {
            // Extract and prepare propertyId for multiple requests
            $propertyIds = [];
            if (isset($filters['propertyId'])) {
                if (is_array($filters['propertyId'])) {
                    $propertyIds = array_values($filters['propertyId']);
                } else {
                    $propertyIds = [$filters['propertyId']];
                }
                unset($filters['propertyId']); // Remove from filters, will be added per request
            }

            // Convert status array to comma-separated string if needed
            if (isset($filters['status']) && is_array($filters['status'])) {
                $filters['status'] = implode(',', $filters['status']);
            }

            // If no properties specified, return empty
            if (empty($propertyIds)) {
                return [
                    'success' => false,
                    'error' => 'No properties specified',
                    'data' => []
                ];
            }

            // Query each property separately and merge results
            $allBookings = [];
            foreach ($propertyIds as $propertyId) {
                $propertyFilters = array_merge($filters, ['propertyId' => (int)$propertyId]);

                Log::info('Beds24 Get Bookings Request', LogSanitizer::context([
                    'propertyId' => $propertyId,
                    'filters' => $propertyFilters,
                ]));

                $response = $this->apiCall('GET', '/bookings', $propertyFilters);

                $result = $response->json();

                Log::info('Beds24 Get Bookings Response', LogSanitizer::context([
                    'propertyId' => $propertyId,
                    'status_code' => $response->status(),
                    'count' => $result['count'] ?? 0,
                    'success' => $response->successful(),
                    'has_data' => isset($result['data']),
                    'data_count' => isset($result['data']) ? count($result['data']) : 0,
                    'sample_booking' => isset($result['data'][0]) ? array_keys($result['data'][0]) : [],
                ]));

                if ($response->successful() && isset($result['data']) && is_array($result['data'])) {
                    $allBookings = array_merge($allBookings, $result['data']);
                }
            }

            // Return merged results
            if (!empty($allBookings)) {
                return [
                    'success' => true,
                    'data' => $allBookings,
                    'count' => count($allBookings)
                ];
            }

            return [
                'success' => false,
                'error' => 'No bookings found',
                'data' => []
            ];

        } catch (\Exception $e) {
            Log::error('Beds24 Get Bookings Error', [
                'error' => $e->getMessage(),
                'filters' => $filters
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'data' => []
            ];
        }
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

                $response = $this->apiCall('GET', '/inventory/rooms/calendar', $params);

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

        Log::info('Beds24 Create Multiple Bookings Request', LogSanitizer::context([
            'payload' => $payload,
            'count' => count($payload),
            'makeGroup' => $makeGroup,
        ]));

        try {
            $response = $this->apiCall('POST', '/bookings', $payload);

            $result = $response->json();

            Log::info('Beds24 Create Multiple Bookings Response', LogSanitizer::context(['response' => $result]));

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

    /**
     * Fetch guest info (name, email, phone) from Beds24 API.
     * The webhook often has empty infoItems, so we fetch from API.
     */
    public function fetchGuestInfo(string $bookingId): array
    {
        try {
            $response = $this->apiCall('GET', '/bookings', ['id' => $bookingId, 'includeInfoItems' => 'true']);

            $result = $response->json();
            $bookingData = $result['data'][0] ?? $result[0] ?? null;

            if (!$bookingData) {
                Log::info('Beds24 fetchGuestInfo: No data', ['booking_id' => $bookingId]);
                return ['guest_name' => '', 'guest_email' => null, 'guest_phone' => null];
            }

            $firstName = '';
            $lastName = '';
            $email = null;
            $phone = null;

            // Extract from infoItems (Beds24 V2 stores guest info here)
            $infoItems = $bookingData['infoItems'] ?? [];
            foreach ($infoItems as $item) {
                $code = $item['code'] ?? '';
                $text = trim($item['text'] ?? '');
                match ($code) {
                    'guestFirstName', 'firstName' => $firstName = $text,
                    'guestLastName', 'lastName' => $lastName = $text,
                    'guestEmail', 'email' => $email = $text ?: null,
                    'guestPhone', 'phone', 'mobile' => $phone = $text ?: null,
                    default => null,
                };
            }

            // Fallback: check top-level fields
            if (!$firstName && !$lastName) {
                $firstName = $bookingData['guestFirstName'] ?? $bookingData['firstName'] ?? '';
                $lastName = $bookingData['guestLastName'] ?? $bookingData['lastName'] ?? '';
            }

            $guestName = trim($firstName . ' ' . $lastName);

            Log::info('Beds24 fetchGuestInfo: Result', LogSanitizer::context([
                'booking_id' => $bookingId,
                'guest_name' => $guestName,
                'info_items_count' => count($infoItems),
            ]));

            return [
                'guest_name' => $guestName,
                'guest_email' => $email,
                'guest_phone' => $phone,
            ];
        } catch (\Throwable $e) {
            Log::error('Beds24 fetchGuestInfo: Error', [
                'booking_id' => $bookingId,
                'error' => $e->getMessage(),
            ]);
            return ['guest_name' => '', 'guest_email' => null, 'guest_phone' => null];
        }
    }

    /**
     * Get room unit statuses (housekeeping) for a property.
     * Returns flat array: [ ['room_number' => '7', 'status' => 'clean', 'color' => '1578db', 'room_type' => 'Twin Room', 'room_type_id' => 94986, 'unit_id' => 1], ... ]
     */
    public function getRoomStatuses(int $propertyId): array
    {
        try {
            $response = $this->apiCall('GET', '/properties', [
                'id' => $propertyId,
                'includeAllRooms' => 'true',
                'includeUnitDetails' => 'true',
            ]);

            $result = $response->json();
            $property = $result['data'][0] ?? null;

            if (!$property) {
                Log::warning('Beds24 getRoomStatuses: no property data', ['propertyId' => $propertyId]);
                return [];
            }

            $rooms = [];
            foreach ($property['roomTypes'] ?? [] as $rt) {
                foreach ($rt['units'] ?? [] as $unit) {
                    $rooms[] = [
                        'room_number'  => $unit['name'] ?? '',
                        'status'       => $unit['statusText'] ?? 'unknown',
                        'color'        => $unit['statusColor'] ?? '',
                        'note'         => $unit['note'] ?? $unit['notes'] ?? '',
                        'room_type'    => $rt['name'] ?? '',
                        'room_type_id' => $rt['id'],
                        'unit_id'      => $unit['id'],
                    ];
                }
            }

            // Sort by room number (numeric)
            usort($rooms, fn($a, $b) => (int) $a['room_number'] <=> (int) $b['room_number']);

            return $rooms;
        } catch (\Throwable $e) {
            Log::error('Beds24 getRoomStatuses error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Update a room unit's housekeeping status in Beds24.
     * Requires propertyId, roomTypeId, unitId (within room type), and new statusText.
     */
    public function updateRoomStatus(int $propertyId, int $roomTypeId, int $unitId, string $statusText): bool
    {
        try {
            $response = $this->apiCall('POST', '/properties', [
                [
                    'id' => $propertyId,
                    'roomTypes' => [
                        [
                            'id' => $roomTypeId,
                            'units' => [
                                [
                                    'id' => $unitId,
                                    'statusText' => $statusText,
                                ]
                            ]
                        ]
                    ]
                ]
            ]);

            $result = $response->json();
            $success = $result[0]['success'] ?? false;

            Log::info('Beds24 updateRoomStatus', [
                'propertyId' => $propertyId,
                'roomTypeId' => $roomTypeId,
                'unitId' => $unitId,
                'statusText' => $statusText,
                'success' => $success,
            ]);

            return $success;
        } catch (\Throwable $e) {
            Log::error('Beds24 updateRoomStatus error', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Update multiple room units' status at once (batch).
     * $rooms = [ ['room_type_id' => 94984, 'unit_id' => 3, 'status' => 'clean'], ... ]
     */
    public function updateRoomStatusBatch(int $propertyId, array $rooms, string $statusText): bool
    {
        try {
            // Group by room type
            $byType = [];
            foreach ($rooms as $room) {
                $rtId = $room['room_type_id'];
                $byType[$rtId][] = [
                    'id' => $room['unit_id'],
                    'statusText' => $statusText,
                ];
            }

            $roomTypes = [];
            foreach ($byType as $rtId => $units) {
                $roomTypes[] = [
                    'id' => $rtId,
                    'units' => $units,
                ];
            }

            $response = $this->apiCall('POST', '/properties', [
                [
                    'id' => $propertyId,
                    'roomTypes' => $roomTypes,
                ]
            ]);

            $result = $response->json();
            $success = $result[0]['success'] ?? false;

            Log::info('Beds24 updateRoomStatusBatch', [
                'propertyId' => $propertyId,
                'count' => count($rooms),
                'statusText' => $statusText,
                'success' => $success,
            ]);

            return $success;
        } catch (\Throwable $e) {
            Log::error('Beds24 updateRoomStatusBatch error', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Write the rotated refresh token back to .env so that Redis loss never leaves
     * the service with a stale fallback. Redis is the fast path; .env is the durable floor.
     *
     * Uses a simple line-replacement — safe because the key is a single-line scalar.
     * Silently skips if .env is missing or not writable (e.g. read-only deployments).
     */
    private function persistRefreshTokenToEnv(string $token): void
    {
        $envPath = app()->environmentFilePath();

        if (! File::exists($envPath) || ! File::isWritable($envPath)) {
            Log::warning('Beds24: .env not writable — rotated refresh token NOT persisted to disk');
            return;
        }

        $current = File::get($envPath);
        $key     = 'BEDS24_API_V2_REFRESH_TOKEN';

        if (str_contains($current, $key . '=')) {
            $updated = preg_replace(
                '/^' . preg_quote($key, '/') . '=.*/m',
                $key . '=' . $token,
                $current
            );
        } else {
            $updated = $current . PHP_EOL . $key . '=' . $token . PHP_EOL;
        }

        File::put($envPath, $updated);

        Log::info('Beds24 rotated refresh token persisted to .env');
    }

}
