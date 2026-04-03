<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\BookingFinance;
use App\DTOs\CreateBookingRequest;
use App\DTOs\RecordPaymentRequest;
use App\Enums\ChargeWriteStatus;
use App\Enums\FinanceWritePolicy;
use App\Models\RoomUnitMapping;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class BookingCommandService
{
    public function __construct(
        private Beds24BookingService    $beds24,
        private TelegramKeyboardService $keyboard,
        private StaffAuthorizationService $authService,
    ) {}

    // ──────────────────────────────────────────────────────────────────────────
    // Public API
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Route and execute a parsed intent on behalf of an authenticated staff member.
     * Returns an operator-facing plain-text response string.
     */
    public function handle(array $parsed, User $staff): string
    {
        $intent = $parsed['intent'] ?? 'unknown';

        switch ($intent) {
            case 'check_availability':
                return $this->handleCheckAvailability($parsed);

            case 'create_booking':
                return $this->handleCreateBooking($parsed, $staff);

            case 'view_bookings':
                return $this->handleViewBookings($parsed);

            case 'modify_booking':
                return $this->handleModifyBooking($parsed, $staff);

            case 'cancel_booking':
                return $this->handleCancelBooking($parsed, $staff);

            case 'record_payment':
                return $this->handleRecordPayment($parsed, $staff);

            default:
                return "I did not quite understand that. Try:\n\n" .
                       "- check avail jan 2-3\n" .
                       "- book room 12 under John Walker jan 2-3 tel +1234567890 email ok@ok.com\n" .
                       "- cancel booking #123456\n" .
                       "- help";
        }
    }

    /**
     * Handle a Telegram callback query (inline button press).
     * Answers the callback, authenticates the caller, and dispatches to the
     * appropriate handler. All Telegram I/O is done via $telegram.
     */
    public function handleCallbackQuery(array $callbackQuery, TelegramBotService $telegram): void
    {
        $chatId          = $callbackQuery['message']['chat']['id'];
        $messageId       = $callbackQuery['message']['message_id'];
        $callbackData    = $callbackQuery['data'];
        $callbackQueryId = $callbackQuery['id'];

        // Check authorization
        $staff = $this->authService->verifyTelegramUser(['callback_query' => $callbackQuery]);

        if (!$staff) {
            $telegram->answerCallbackQuery($callbackQueryId);

            $telegram->sendMessage($chatId, $this->authService->getAuthorizationRequestMessage(), [
                'reply_markup' => [
                    'keyboard' => [[
                        ['text' => '📱 Share Phone Number', 'request_contact' => true]
                    ]],
                    'one_time_keyboard' => true,
                    'resize_keyboard'   => true,
                ],
            ]);
            return;
        }

        // Remove button loading state before any further processing
        $telegram->answerCallbackQuery($callbackQueryId);

        switch ($callbackData) {
            case 'main_menu':
                $telegram->editMessageText($chatId, $messageId, "🏨 Booking Bot Menu\n\nChoose an option below:", [
                    'reply_markup' => $this->keyboard->formatForApi($this->keyboard->getMainMenu()),
                ]);
                break;

            case 'view_arrivals_today':
                $response = $this->handleViewBookings(['filter_type' => 'arrivals_today']);
                $telegram->editMessageText($chatId, $messageId, $response, [
                    'reply_markup' => $this->keyboard->formatForApi($this->keyboard->getBackButton()),
                ]);
                break;

            case 'view_departures_today':
                $response = $this->handleViewBookings(['filter_type' => 'departures_today']);
                $telegram->editMessageText($chatId, $messageId, $response, [
                    'reply_markup' => $this->keyboard->formatForApi($this->keyboard->getBackButton()),
                ]);
                break;

            case 'view_current':
                $response = $this->handleViewBookings(['filter_type' => 'current']);
                $telegram->editMessageText($chatId, $messageId, $response, [
                    'reply_markup' => $this->keyboard->formatForApi($this->keyboard->getBackButton()),
                ]);
                break;

            case 'view_new':
                $response = $this->handleViewBookings(['filter_type' => 'new']);
                $telegram->editMessageText($chatId, $messageId, $response, [
                    'reply_markup' => $this->keyboard->formatForApi($this->keyboard->getBackButton()),
                ]);
                break;

            case 'search_guest':
            case 'check_availability':
            case 'create_booking':
            case 'modify_booking':
            case 'cancel_booking':
                $instructions = match ($callbackData) {
                    'search_guest'       => "Please type the guest name to search.\n\nExample: search for John Smith",
                    'check_availability' => "Please type dates to check availability.\n\nExample: check avail jan 15-17",
                    'create_booking'     => "Please type booking details.\n\nExample: book room 12 under John Smith jan 15-17 tel +1234567890 email john@email.com",
                    'modify_booking'     => "Please type booking ID and changes.\n\nExample: modify booking #123456 to jan 15-17",
                    'cancel_booking'     => "Please type booking ID to cancel.\n\nExample: cancel booking #123456",
                };

                $telegram->editMessageText($chatId, $messageId, $instructions, [
                    'reply_markup' => $this->keyboard->formatForApi($this->keyboard->getBackButton()),
                ]);
                break;

            default:
                $telegram->editMessageText($chatId, $messageId, "Unknown action. Please try again.", [
                    'reply_markup' => $this->keyboard->formatForApi($this->keyboard->getMainMenu()),
                ]);
                break;
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Intent handlers
    // ──────────────────────────────────────────────────────────────────────────

    protected function handleCheckAvailability(array $parsed): string
    {
        $dates = $parsed['dates'] ?? null;

        if (!$dates || empty($dates['check_in']) || empty($dates['check_out'])) {
            return 'Please provide valid dates. Example: check avail jan 2-3';
        }

        $checkIn  = $dates['check_in'];
        $checkOut = $dates['check_out'];

        // Get all configured rooms
        $rooms = RoomUnitMapping::all();

        if ($rooms->isEmpty()) {
            return 'No rooms configured in system.';
        }

        try {
            // Get property IDs for availability check
            $propertyIds = $rooms->pluck('property_id')->unique()->toArray();

            // Check availability using calendar endpoint
            $availability = $this->beds24->checkAvailability($checkIn, $checkOut, $propertyIds);

            if (!$availability['success']) {
                throw new \Exception($availability['error'] ?? 'API request failed');
            }

            $availableRoomsApi = $availability['availableRooms'] ?? [];
            $nights            = $availability['nights'] ?? [];
            $nightCount        = count($nights);

            // Map API room data to our room units
            $availableUnits = [];
            foreach ($availableRoomsApi as $apiRoom) {
                $roomId     = $apiRoom['roomId'];
                $quantity   = $apiRoom['quantity'];
                $roomName   = $apiRoom['roomName'];
                $propertyId = $apiRoom['propertyId'];

                // Find units for this room type - only take as many as available quantity
                $units = $rooms->where('room_id', $roomId)
                                ->where('property_id', $propertyId)
                                ->sortBy('unit_name')
                                ->take($quantity);

                foreach ($units as $unit) {
                    $availableUnits[] = [
                        'unit'     => $unit,
                        'quantity' => $quantity,
                        'roomName' => $roomName,
                    ];
                }
            }

            // Format output according to spec
            if (empty($availableUnits)) {
                return "No rooms available for the entire stay.\n" .
                       "Check-in: {$checkIn}\n" .
                       "Check-out: {$checkOut}\n\n" .
                       "All rooms are booked for at least one night in this period.";
            }

            // Calculate date range for display (e.g., "Oct 16–19")
            $checkInDt  = new \DateTimeImmutable($checkIn);
            $checkOutDt = new \DateTimeImmutable($checkOut);
            $monthName  = $checkInDt->format('M');
            $startDay   = $checkInDt->format('j');
            $endDay     = $checkOutDt->format('j');
            $dateRange  = "{$monthName} {$startDay}–{$endDay}";

            // Determine night text
            if ($nightCount == 1) {
                $nightText = 'night';
            } elseif ($nightCount == 2) {
                $nightText = 'both nights';
            } elseif ($nightCount == 3) {
                $nightText = 'all three nights';
            } else {
                $nightText = "all {$nightCount} nights";
            }

            // Build response
            $response = "Rooms available for the entire stay ({$nightText}):\n\n";

            // Group by property for organization
            $byProperty = collect($availableUnits)->groupBy(function ($item) {
                return $item['unit']->property_name;
            });

            foreach ($byProperty as $propertyName => $propertyUnits) {
                $response .= "━━━━━ " . strtoupper($propertyName) . " ━━━━━\n\n";

                // Group by room type
                $byRoomType = $propertyUnits->groupBy('roomName');

                foreach ($byRoomType as $roomTypeName => $typeUnits) {
                    $totalQty = $typeUnits->first()['quantity'];
                    $units    = $typeUnits->pluck('unit');

                    $response .= "{$roomTypeName} — {$totalQty} " . ($totalQty == 1 ? 'room' : 'rooms') . "\n";
                    $response .= "Units: " . $units->pluck('unit_name')->sort()->implode(', ') . "\n";

                    $firstUnit = $units->first();
                    $response .= "Type: " . ucfirst($firstUnit->room_type) . " | Max: {$firstUnit->max_guests} guests\n";
                    if ($firstUnit->base_price > 0) {
                        $response .= "Price: $" . $firstUnit->base_price . "/night\n";
                    }
                    $response .= "\n";
                }
            }

            $response .= "All other room types break on at least one night, so they're not available for the whole {$dateRange} stay.\n\n";
            $response .= "To book, use: book room [NUMBER] under [NAME] {$checkIn} to {$checkOut} tel [PHONE] email [EMAIL]";

            return $response;

        } catch (\Exception $e) {
            Log::error('Availability check failed', ['error' => $e->getMessage()]);

            return "Error checking availability: " . $e->getMessage() . "\n\n" .
                   "Please try again or contact support.";
        }
    }

    protected function handleCreateBooking(array $parsed, User $staff): string
    {
        // 1. Normalize raw parser output into a typed request
        $request = CreateBookingRequest::fromParsed($parsed, $staff->name);

        // 2. Validate presence, date format/order, room list, duplicates
        if ($error = $request->validationError()) {
            return $error;
        }

        // 3. Resolve all requested rooms before any booking is created (preflight)
        $resolution = $this->resolveRequestedRooms($request->rooms);

        if (!$resolution['ok']) {
            return $resolution['message'];
        }

        $mappings = $resolution['mappings']; // RoomUnitMapping[]

        // 4. Availability preflight for all resolved rooms — avoids obvious partial failures
        $availabilityResult = $this->checkRoomsAvailability($mappings, $request->checkIn, $request->checkOut);

        if (!$availabilityResult['ok']) {
            // Preflight API call failed — continue but warn the operator
            Log::warning('Availability preflight bypassed, proceeding to sequential creation', [
                'reason' => $availabilityResult['reason'],
            ]);
        } elseif (!empty($availabilityResult['unavailable'])) {
            $unitList = implode(', ', array_map(fn($m) => "Unit {$m->unit_name}", $availabilityResult['unavailable']));
            return "❌ Rooms Not Available\n\n"
                 . "The following rooms are already booked for {$request->checkIn} → {$request->checkOut}:\n"
                 . "  {$unitList}\n\n"
                 . "Please choose different dates or different rooms.";
        }

        $preflightBypassed = !$availabilityResult['ok'];

        // 5. Sequential creation — Beds24 batch POST /bookings is non-transactional
        $successes = [];
        $failures  = [];

        foreach ($mappings as $mapping) {
            try {
                $result = $this->beds24->createBooking([
                    'property_id' => $mapping->property_id,
                    'room_id'     => $mapping->room_id,
                    'check_in'    => $request->checkIn,
                    'check_out'   => $request->checkOut,
                    'guest_name'  => $request->guestName,
                    'guest_phone' => $request->guestPhone,
                    'guest_email' => $request->guestEmail,
                    'notes'       => 'Created by ' . $request->createdBy . ' via Telegram Bot',
                ]);

                if ($result['success'] ?? false) {
                    $successes[] = [
                        'unit'       => $mapping->unit_name,
                        'room'       => $mapping->room_name,
                        'bookingId'  => $result['bookingId'] ?? $result['id'] ?? 'unknown',
                        'propertyId' => (int) $mapping->property_id,
                    ];
                } else {
                    $failures[] = ['unit' => $mapping->unit_name, 'error' => 'API returned failure'];
                }
            } catch (\Exception $e) {
                Log::error('Booking creation failed in multi-room flow', [
                    'unit'  => $mapping->unit_name,
                    'error' => $e->getMessage(),
                ]);
                $failures[] = ['unit' => $mapping->unit_name, 'error' => $e->getMessage()];
            }
        }

        // 6. Write quoted charge (if finance provided and feature enabled)
        $chargeStatus = $this->determineChargeWrite($request->finance, $successes, $request->createdBy);

        // 7. Format operator-facing result
        return $this->formatCreateBookingResult($request, $successes, $failures, $preflightBypassed, $chargeStatus);
    }

    protected function handleViewBookings(array $parsed): string
    {
        try {
            // Build filters based on parsed intent
            $filters    = [];
            $filterType = $parsed['filter_type'] ?? null;

            // Get all configured room properties
            $rooms       = RoomUnitMapping::all();
            $propertyIds = $rooms->pluck('property_id')->unique()->toArray();
            $filters['propertyId'] = $propertyIds;

            // Determine filter type from parsed data
            if ($filterType) {
                switch ($filterType) {
                    case 'arrivals_today':
                        $today                = date('Y-m-d');
                        $filters['arrivalFrom'] = $today;
                        $filters['arrivalTo']   = $today;
                        $title = "Arrivals Today (" . date('M j, Y') . ")";
                        break;

                    case 'departures_today':
                        $today                   = date('Y-m-d');
                        $filters['departureFrom'] = $today;
                        $filters['departureTo']   = $today;
                        $title = "Departures Today (" . date('M j, Y') . ")";
                        break;

                    case 'current':
                        // In-house guests: arrival before or on today, departure after today
                        $today                   = date('Y-m-d');
                        $filters['arrivalTo']    = $today;
                        $filters['departureFrom'] = date('Y-m-d', strtotime('+1 day'));
                        $title = "Current Bookings (In-House)";
                        break;

                    case 'new':
                        // New/unconfirmed bookings - use status filter
                        $filters['status'] = ['new', 'request'];
                        $title = "New Bookings (Unconfirmed)";
                        break;

                    default:
                        // Default: show upcoming bookings (arrivals from today onwards)
                        $filters['arrivalFrom'] = date('Y-m-d');
                        $title = "Upcoming Bookings";
                }
            } else {
                // Default: show upcoming bookings
                $filters['arrivalFrom'] = date('Y-m-d');
                $title = "Upcoming Bookings";
            }

            // Check for search string (guest name search)
            if (isset($parsed['search_string']) && !empty($parsed['search_string'])) {
                $filters['searchString'] = $parsed['search_string'];
                $title = "Search Results: " . $parsed['search_string'];
            }

            // Check for date range filters
            $dates = $parsed['dates'] ?? null;
            if ($dates) {
                if (!empty($dates['check_in'])) {
                    $filters['arrivalFrom'] = $dates['check_in'];
                }
                if (!empty($dates['check_out'])) {
                    $filters['arrivalTo'] = $dates['check_out'];
                }
            }

            Log::info('Fetching bookings', ['filters' => $filters, 'title' => $title]);

            // Get bookings from Beds24
            $result = $this->beds24->getBookings($filters);

            Log::info('Bookings result', [
                'success'    => $result['success'] ?? false,
                'count'      => $result['count'] ?? 0,
                'has_data'   => isset($result['data']),
                'data_empty' => empty($result['data']),
            ]);

            if (!isset($result['data']) || empty($result['data'])) {
                return "📭 No Bookings Found\n\n" .
                       "Filter: {$title}\n" .
                       "Date: " . date('M j, Y') . "\n\n" .
                       "No bookings match your search criteria.";
            }

            $bookings = $result['data'];
            $count    = count($bookings);

            // Build response
            $response  = "{$title}\n";
            $response .= "━━━━━━━━━━━━━━━━━━━━\n";
            $response .= "Found {$count} " . ($count == 1 ? 'booking' : 'bookings') . "\n\n";

            // Limit to first 10 bookings to avoid message length issues
            $displayCount = min($count, 10);

            for ($i = 0; $i < $displayCount; $i++) {
                $booking = $bookings[$i];

                // Build guest name from firstName and lastName
                $guestName = trim(($booking['firstName'] ?? '') . ' ' . ($booking['lastName'] ?? ''));
                if (empty($guestName)) {
                    $guestName = 'N/A';
                }

                // Look up room name from roomId
                $roomName = 'N/A';
                if (isset($booking['roomId'])) {
                    $roomMapping = $rooms->where('room_id', $booking['roomId'])->first();
                    if ($roomMapping) {
                        $roomName = $roomMapping->room_name . ' (Unit ' . $roomMapping->unit_name . ')';
                    }
                }

                $response .= "#{$booking['id']}\n";
                $response .= "Guest: {$guestName}\n";
                $response .= "Room: {$roomName}\n";
                $response .= "Dates: " . ($booking['arrival'] ?? 'N/A') . " → " . ($booking['departure'] ?? 'N/A') . "\n";

                if (isset($booking['status'])) {
                    $statusEmoji = match ($booking['status']) {
                        'confirmed' => '✅',
                        'request'   => '❓',
                        'cancelled' => '❌',
                        'new'       => '🆕',
                        default     => '•',
                    };
                    $response .= "Status: {$statusEmoji} " . ucfirst($booking['status']) . "\n";
                }

                if (isset($booking['numAdult']) || isset($booking['numChild'])) {
                    $adults   = $booking['numAdult'] ?? 0;
                    $children = $booking['numChild'] ?? 0;
                    $response .= "Guests: {$adults} " . ($adults == 1 ? 'adult' : 'adults');
                    if ($children > 0) {
                        $response .= ", {$children} " . ($children == 1 ? 'child' : 'children');
                    }
                    $response .= "\n";
                }

                if (isset($booking['price'])) {
                    $response .= "Price: $" . number_format($booking['price'], 2) . "\n";
                }

                // Add separator line between bookings (except for last one)
                if ($i < $displayCount - 1) {
                    $response .= "─────────────────────\n";
                }
                $response .= "\n";
            }

            if ($count > 10) {
                $response .= "... and " . ($count - 10) . " more bookings\n";
                $response .= "(Showing first 10 results)\n";
            }

            $response .= "\nTo modify: modify booking #[ID]\n";
            $response .= "To cancel: cancel booking #[ID]";

            return $response;

        } catch (\Exception $e) {
            Log::error('View bookings failed', [
                'error'  => $e->getMessage(),
                'parsed' => $parsed,
            ]);

            return "Error fetching bookings: " . $e->getMessage() . "\n\n" .
                   "Please try again or contact support.";
        }
    }

    protected function handleCancelBooking(array $parsed, User $staff): string
    {
        // Extract booking ID from parsed data
        $bookingId = $parsed['booking_id'] ?? null;

        // If not in parsed data, try to extract from raw text (fallback)
        if (!$bookingId && isset($parsed['_raw_message'])) {
            if (preg_match('/#?(\d+)/', $parsed['_raw_message'], $matches)) {
                $bookingId = $matches[1];
            }
        }

        if (!$bookingId) {
            return "Please provide a booking ID to cancel.\n\n" .
                   "Example: cancel booking #123456\n" .
                   "Or: cancel booking 123456";
        }

        try {
            // First, try to get the booking details to show in confirmation
            Log::info('Fetching booking details before cancellation', ['booking_id' => $bookingId]);

            $bookingDetails = null;
            try {
                $getResult = $this->beds24->getBooking($bookingId);
                if (isset($getResult['data']) && !empty($getResult['data'])) {
                    $bookingDetails = $getResult['data'][0] ?? $getResult['data'];
                }
            } catch (\Exception $e) {
                Log::warning('Could not fetch booking details', ['error' => $e->getMessage()]);
                // Continue with cancellation even if we can't get details
            }

            Log::info('Cancelling booking', [
                'booking_id' => $bookingId,
                'staff'      => $staff->name,
            ]);

            $reason = 'Cancelled by ' . $staff->name . ' via Telegram Bot';
            $result = $this->beds24->cancelBooking($bookingId, $reason);

            Log::info('Cancel booking API response', ['result' => $result]);

            // Beds24 API returns array format: [{"success": true, ...}]
            $success = false;
            if (is_array($result)) {
                if (isset($result['success']) && $result['success']) {
                    $success = true;
                } elseif (isset($result[0]['success']) && $result[0]['success']) {
                    $success = true;
                } elseif (isset($result[0]) && !isset($result[0]['error'])) {
                    // Sometimes success is implied by no error
                    $success = true;
                }
            }

            if ($success) {
                $response  = "✅ Booking Cancelled Successfully\n\n";
                $response .= "Booking ID: #{$bookingId}\n";

                if ($bookingDetails) {
                    if (isset($bookingDetails['roomName'])) {
                        $response .= "Room: {$bookingDetails['roomName']}\n";
                    }
                    if (isset($bookingDetails['guestName'])) {
                        $response .= "Guest: {$bookingDetails['guestName']}\n";
                    }
                    if (isset($bookingDetails['arrival']) && isset($bookingDetails['departure'])) {
                        $response .= "Dates: {$bookingDetails['arrival']} to {$bookingDetails['departure']}\n";
                    }
                }

                $response .= "\nThe booking has been cancelled in Beds24.";

                return $response;
            } else {
                $errorMsg = 'Unknown error';
                if (isset($result['error'])) {
                    $errorMsg = $result['error'];
                } elseif (isset($result[0]['error'])) {
                    $errorMsg = $result[0]['error'];
                }

                throw new \Exception($errorMsg);
            }

        } catch (\Exception $e) {
            Log::error('Booking cancellation failed', [
                'booking_id' => $bookingId,
                'error'      => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);

            return "❌ Booking Cancellation Failed\n\n" .
                   "Booking ID: #{$bookingId}\n" .
                   "Error: {$e->getMessage()}\n\n" .
                   "Please check the booking ID and try again, or cancel manually in Beds24.";
        }
    }

    protected function handleModifyBooking(array $parsed, User $staff): string
    {
        // Extract booking ID
        $bookingId = $parsed['booking_id'] ?? null;

        if (!$bookingId) {
            return "Please provide a booking ID to modify.\n\n" .
                   "Example: change booking #123456 to jan 5-7\n" .
                   "Or: modify booking #123456 guest name to Jane Smith\n" .
                   "Or: update booking #123456 phone to +998123456789";
        }

        try {
            Log::info('Fetching booking details for modification', ['booking_id' => $bookingId]);

            $getResult = $this->beds24->getBooking($bookingId);

            if (!isset($getResult['data']) || empty($getResult['data'])) {
                return "❌ Booking Not Found\n\n" .
                       "Booking ID: #{$bookingId}\n" .
                       "Could not find this booking. Please check the ID and try again.";
            }

            $currentBooking = $getResult['data'][0] ?? $getResult['data'];

            // Build the changes array
            $changes        = [];
            $changesSummary = [];

            // Check for date changes
            $dates = $parsed['dates'] ?? null;
            if ($dates) {
                if (!empty($dates['check_in'])) {
                    $changes['arrival']     = $dates['check_in'];
                    $changesSummary[] = "Check-in: " . ($currentBooking['arrival'] ?? 'N/A') . " → " . $dates['check_in'];
                }
                if (!empty($dates['check_out'])) {
                    $changes['departure']   = $dates['check_out'];
                    $changesSummary[] = "Check-out: " . ($currentBooking['departure'] ?? 'N/A') . " → " . $dates['check_out'];
                }
            }

            // Check for guest info changes
            $guest = $parsed['guest'] ?? null;
            if ($guest) {
                if (!empty($guest['name'])) {
                    // Parse full name into first and last
                    $nameParts = explode(' ', $guest['name'], 2);
                    $changes['firstName'] = $nameParts[0];
                    if (isset($nameParts[1])) {
                        $changes['lastName'] = $nameParts[1];
                    }
                    $changesSummary[] = "Guest: " . ($currentBooking['guestName'] ?? 'N/A') . " → " . $guest['name'];
                }
                if (!empty($guest['phone'])) {
                    $changes['mobile']    = $guest['phone'];
                    $changesSummary[] = "Phone: " . ($currentBooking['mobile'] ?? 'N/A') . " → " . $guest['phone'];
                }
                if (!empty($guest['email'])) {
                    $changes['email']     = $guest['email'];
                    $changesSummary[] = "Email: " . ($currentBooking['email'] ?? 'N/A') . " → " . $guest['email'];
                }
            }

            // Check for room change
            $room = $parsed['room'] ?? null;
            if ($room && !empty($room['unit_name'])) {
                $unitName     = $room['unit_name'];
                $propertyHint = $parsed['property'] ?? null;

                $query = RoomUnitMapping::where('unit_name', $unitName);

                if ($propertyHint) {
                    if (stripos($propertyHint, 'premium') !== false) {
                        $query->where('property_id', config('services.beds24.properties.premium'));
                    } elseif (stripos($propertyHint, 'hotel') !== false) {
                        $query->where('property_id', config('services.beds24.properties.hotel'));
                    }
                }

                $matchingRooms = $query->get();

                if ($matchingRooms->isEmpty()) {
                    return "❌ Modification Failed\n\n" .
                           "Room {$unitName} not found. Please check the room number.";
                }

                if ($matchingRooms->count() > 1) {
                    $propertyList = $matchingRooms->map(function ($r) {
                        return $r->property_name . ' (Unit ' . $r->unit_name . ')';
                    })->join("\n");

                    return "Multiple rooms found with unit {$unitName}:\n\n" .
                           $propertyList . "\n\n" .
                           "Please specify the property.\n" .
                           "Example: change booking #{$bookingId} to room {$unitName} at Premium";
                }

                $roomMapping    = $matchingRooms->first();
                $changes['roomId'] = (int) $roomMapping->room_id;
                $changesSummary[] = "Room: " . ($currentBooking['roomName'] ?? 'N/A') . " → Unit {$unitName} ({$roomMapping->room_name})";
            }

            if (empty($changes)) {
                return "No changes detected.\n\n" .
                       "Please specify what you want to modify:\n" .
                       "- Dates: change booking #{$bookingId} to jan 5-7\n" .
                       "- Guest name: modify booking #{$bookingId} guest name to Jane Smith\n" .
                       "- Phone: update booking #{$bookingId} phone to +998123456789\n" .
                       "- Room: change booking #{$bookingId} to room 14";
            }

            // Always compute effective dates from the change set, falling back to current booking values.
            // This must be done before any date validation or availability check, regardless of
            // whether dates are part of the requested changes.
            $effectiveArrival   = $changes['arrival']   ?? $currentBooking['arrival'];
            $effectiveDeparture = $changes['departure'] ?? $currentBooking['departure'];

            // Validate date order using Carbon for reliable comparison across formats
            $arrivalDate   = \Carbon\Carbon::parse($effectiveArrival);
            $departureDate = \Carbon\Carbon::parse($effectiveDeparture);

            if (!$arrivalDate->lt($departureDate)) {
                return "❌ Invalid Dates\n\n" .
                       "Check-in date must be before check-out date.\n" .
                       "Requested: {$effectiveArrival} to {$effectiveDeparture}";
            }

            // Check if dates are actually changing to avoid unnecessary availability calls
            $currentArrival   = $currentBooking['arrival'];
            $currentDeparture = $currentBooking['departure'];
            $datesChanged = ($effectiveArrival !== $currentArrival) || ($effectiveDeparture !== $currentDeparture);

            if ($datesChanged) {
                $roomId     = $currentBooking['roomId']     ?? null;
                $propertyId = $currentBooking['propertyId'] ?? null;

                if ($roomId && $propertyId) {
                    try {
                        Log::info('Checking room availability for date change', [
                            'booking_id' => $bookingId,
                            'room_id'    => $roomId,
                            'current'    => [$currentArrival, $currentDeparture],
                            'new'        => [$effectiveArrival, $effectiveDeparture],
                        ]);

                        $availability = $this->beds24->checkAvailability($effectiveArrival, $effectiveDeparture, [$propertyId]);

                        if ($availability['success']) {
                            $availableRooms = $availability['availableRooms'] ?? [];
                            $roomAvailable  = false;

                            foreach ($availableRooms as $availRoom) {
                                if ($availRoom['roomId'] == $roomId && $availRoom['quantity'] > 0) {
                                    $roomAvailable = true;
                                    break;
                                }
                            }

                            if (!$roomAvailable) {
                                $roomName = $currentBooking['roomName'] ?? 'Room';
                                return "Room Not Available\n\n" .
                                       "Cannot extend/modify booking #{$bookingId}\n" .
                                       "Room: {$roomName}\n" .
                                       "Requested: {$effectiveArrival} to {$effectiveDeparture}\n\n" .
                                       "This room is booked by another guest during the new period.\n" .
                                       "Please choose different dates or cancel and rebook.";
                            }

                            Log::info('Room available - proceeding with modification');
                        }
                    } catch (\Exception $e) {
                        Log::warning('Availability check failed during modify: ' . $e->getMessage());
                    }
                }
            }

            Log::info('Modifying booking', [
                'booking_id' => $bookingId,
                'changes'    => $changes,
                'staff'      => $staff->name,
            ]);

            $result = $this->beds24->modifyBooking($bookingId, $changes);

            Log::info('Modify booking API response', ['result' => $result]);

            $success = false;
            if (is_array($result)) {
                if (isset($result['success']) && $result['success']) {
                    $success = true;
                } elseif (isset($result[0]['success']) && $result[0]['success']) {
                    $success = true;
                } elseif (isset($result[0]) && !isset($result[0]['error'])) {
                    $success = true;
                }
            }

            if ($success) {
                $response  = "✅ Booking Modified Successfully\n\n";
                $response .= "Booking ID: #{$bookingId}\n\n";
                $response .= "Changes:\n";
                foreach ($changesSummary as $change) {
                    $response .= "  • {$change}\n";
                }
                $response .= "\n";

                if (isset($changes['arrival']) || isset($changes['departure'])) {
                    $response .= "New Dates: " . ($changes['arrival'] ?? $currentBooking['arrival']) .
                                 " to " . ($changes['departure'] ?? $currentBooking['departure']) . "\n";
                }
                if (!isset($changes['firstName'])) {
                    $response .= "Guest: " . ($currentBooking['guestName'] ?? 'N/A') . "\n";
                }
                if (isset($currentBooking['roomName']) && !isset($changes['roomId'])) {
                    $response .= "Room: " . $currentBooking['roomName'] . "\n";
                }

                $response .= "\nThe booking has been updated in Beds24.";

                return $response;
            } else {
                $errorMsg = 'Unknown error';
                if (isset($result['error'])) {
                    $errorMsg = $result['error'];
                } elseif (isset($result[0]['error'])) {
                    $errorMsg = $result[0]['error'];
                }

                throw new \Exception($errorMsg);
            }

        } catch (\Exception $e) {
            Log::error('Booking modification failed', [
                'booking_id' => $bookingId,
                'error'      => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);

            return "❌ Booking Modification Failed\n\n" .
                   "Booking ID: #{$bookingId}\n" .
                   "Error: {$e->getMessage()}\n\n" .
                   "Please check the details and try again, or modify manually in Beds24.";
        }
    }

    protected function handleRecordPayment(array $parsed, User $staff): string
    {
        // 1. Build and validate the request
        $request = RecordPaymentRequest::fromParsed($parsed, $staff->name);

        if ($error = $request->validationError()) {
            return $error;
        }

        $bookingId = (int) $request->bookingId;

        // 2. Build audit marker
        $amountCents = (int) round($request->amount * 100);
        $staffSlug   = substr(str_replace(' ', '.', strtolower($request->recordedBy)), 0, 16);
        $method      = $request->method ?? 'unspecified';
        $date        = date('Y-m-d');

        $methodLabel = match ($request->method) {
            'cash'     => 'Cash',
            'card'     => 'Card',
            'transfer' => 'Transfer',
            default    => 'Unspecified',
        };

        $description = "{$methodLabel} payment — BOT-PMT|{$bookingId}|{$amountCents}|{$method}|{$staffSlug}|{$date}";

        // 3. Write to Beds24
        try {
            $this->beds24->writePaymentItem($bookingId, $request->amount, $description);
        } catch (\Throwable $e) {
            Log::error('Payment recording failed', [
                'booking_id' => $bookingId,
                'amount'     => $request->amount,
                'error'      => $e->getMessage(),
            ]);

            return "❌ Payment recording failed.\n\n"
                 . "Booking: #{$bookingId}\n"
                 . "Error: {$e->getMessage()}\n\n"
                 . "Please record the payment manually in Beds24.";
        }

        // 4. Fetch updated balance (non-throwing — null is acceptable)
        $balance = $this->beds24->getBookingBalance($bookingId);

        // 5. Return confirmation
        return $this->formatPaymentConfirmation($request, $balance);
    }

    private function formatPaymentConfirmation(RecordPaymentRequest $request, ?float $balance): string
    {
        $lines = ['✅ Payment Recorded', ''];

        $lines[] = "Booking:     #{$request->bookingId}";
        $lines[] = "Amount:      $" . number_format($request->amount, 2);

        if ($request->method !== null) {
            $methodLabel = match ($request->method) {
                'cash'     => 'Cash',
                'card'     => 'Card',
                'transfer' => 'Transfer',
                default    => ucfirst($request->method),
            };
            $lines[] = "Method:      {$methodLabel}";
        }

        $lines[] = "Recorded by: {$request->recordedBy}";
        $lines[] = "Date:        " . date('Y-m-d');

        if ($balance !== null) {
            $lines[] = '';
            $lines[] = "Balance due: $" . number_format($balance, 2);
        }

        return implode("\n", $lines);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Resolve each BookingRoomRequest to a RoomUnitMapping row.
     *
     * Returns ['ok' => true, 'mappings' => [...]] on success,
     * or      ['ok' => false, 'message' => '...'] on first resolution failure.
     */
    private function resolveRequestedRooms(array $roomRequests): array
    {
        $mappings = [];

        foreach ($roomRequests as $req) {
            $query = RoomUnitMapping::where('unit_name', $req->unitName);

            // Canonical hint set by DTO — no stripos() needed here
            if ($req->propertyHint === 'premium') {
                $query->where('property_id', config('services.beds24.properties.premium'));
            } elseif ($req->propertyHint === 'hotel') {
                $query->where('property_id', config('services.beds24.properties.hotel'));
            }

            $matches = $query->get();

            if ($matches->isEmpty()) {
                return [
                    'ok'      => false,
                    'message' => "Room {$req->unitName} not found. Check the room number and try again.",
                ];
            }

            if ($matches->count() > 1) {
                $list = $matches->map(
                    fn($r) => "  • {$r->property_name} → Unit {$r->unit_name} ({$r->room_name})"
                )->join("\n");

                return [
                    'ok'      => false,
                    'message' => "Unit {$req->unitName} exists in multiple properties:\n{$list}\n\n"
                               . "Specify the property in your command:\n"
                               . "  book room {$req->unitName} at Hotel under ...\n"
                               . "  book room {$req->unitName} at Premium under ...",
                ];
            }

            $mappings[] = $matches->first();
        }

        return ['ok' => true, 'mappings' => $mappings];
    }

    /**
     * Check availability for all resolved room mappings over the requested date range.
     *
     * Returns a structured result:
     *   ['ok' => true,  'unavailable' => [...RoomUnitMapping]]  — preflight ran
     *   ['ok' => false, 'reason' => '...', 'unavailable' => []] — API failure, bypassed
     */
    private function checkRoomsAvailability(array $mappings, string $checkIn, string $checkOut): array
    {
        $propertyIds = array_values(
            array_unique(array_map(fn($m) => (string) $m->property_id, $mappings))
        );

        try {
            $availability   = $this->beds24->checkAvailability($checkIn, $checkOut, $propertyIds);
            $availableRooms = $availability['availableRooms'] ?? [];

            // Index available rooms by roomId for O(1) lookup
            $availableByRoom = [];
            foreach ($availableRooms as $ar) {
                if (($ar['quantity'] ?? 0) > 0) {
                    $availableByRoom[$ar['roomId']] = true;
                }
            }

            $unavailable = [];
            foreach ($mappings as $mapping) {
                if (!isset($availableByRoom[$mapping->room_id])) {
                    $unavailable[] = $mapping;
                }
            }

            return ['ok' => true, 'unavailable' => $unavailable];
        } catch (\Exception $e) {
            // Beds24 availability API is down — non-fatal, creation will still be attempted.
            // Caller surfaces this to the operator via preflightBypassed flag.
            Log::warning('Beds24 availability preflight failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'reason' => $e->getMessage(), 'unavailable' => []];
        }
    }

    /**
     * Build the audit marker string embedded in the invoice item description.
     * Format: "Room charge — BOT-CHG|{propertyId}|{bookingId}|{amountCents}|{staffSlug}|{isoDate}"
     * The pipe-delimited suffix allows programmatic extraction from Beds24 reports.
     */
    private function buildChargeMarker(int $propertyId, int $bookingId, float $amount, string $createdBy): string
    {
        $amountCents = (int) round($amount * 100);
        $staffSlug   = strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($createdBy)));
        $isoDate     = now()->toDateString();

        return "Room charge — BOT-CHG|{$propertyId}|{$bookingId}|{$amountCents}|{$staffSlug}|{$isoDate}";
    }

    /**
     * Decide whether to write a charge item, execute the write, and return the outcome.
     * All finance policy decisions live here — Beds24BookingService stays dumb.
     */
    private function determineChargeWrite(
        ?BookingFinance $finance,
        array $successes,
        string $createdBy,
    ): ChargeWriteStatus {
        if ($finance === null) {
            return ChargeWriteStatus::None;
        }

        if (!config('services.booking_bot.write_charge_items', false)) {
            return ChargeWriteStatus::SkippedFeatureDisabled;
        }

        // Multi-room: combined total can't be split automatically — operator must add per room
        if (count($successes) > 1) {
            return ChargeWriteStatus::SkippedMultiRoomUnsupported;
        }

        // All bookings failed — nothing to attach the charge to
        if (empty($successes)) {
            return ChargeWriteStatus::None;
        }

        $success    = $successes[0];
        $bookingId  = (int) $success['bookingId'];
        $propertyId = (int) ($success['propertyId'] ?? 0);

        try {
            $marker = $this->buildChargeMarker($propertyId, $bookingId, $finance->quotedTotal, $createdBy);
            $this->beds24->writeChargeItem($bookingId, $finance->quotedTotal, $marker);

            // Optionally mirror to top-level price field based on policy
            if (FinanceWritePolicy::fromConfig() === FinanceWritePolicy::InvoiceItemsPlusPriceMirror) {
                $this->beds24->writeBookingPriceField($bookingId, $finance->quotedTotal);
            }

            return ChargeWriteStatus::Written;
        } catch (\Throwable $e) {
            Log::error('Charge write failed after booking creation', [
                'booking_id' => $bookingId,
                'amount'     => $finance->quotedTotal,
                'error'      => $e->getMessage(),
            ]);
            return ChargeWriteStatus::Failed;
        }
    }

    /**
     * Format the operator-facing result of a create-booking attempt.
     * Explicitly lists created booking IDs and failed rooms.
     * Partial-success message includes cancellation instructions.
     */
    private function formatCreateBookingResult(
        CreateBookingRequest $request,
        array $successes,
        array $failures,
        bool $preflightBypassed = false,
        ChargeWriteStatus $chargeStatus = ChargeWriteStatus::None,
    ): string {
        $totalRooms = count($successes) + count($failures);

        if (!empty($failures) && empty($successes)) {
            $header = "❌ Booking Failed";
        } elseif (!empty($failures)) {
            $header = "⚠️ Partial Success — {$totalRooms} rooms requested";
        } else {
            $roomWord = $totalRooms === 1 ? 'Booking' : 'Bookings';
            $header   = "✅ {$roomWord} Created Successfully";
        }

        $lines = [$header, ''];

        if ($preflightBypassed) {
            $lines[] = "⚠️  Availability could not be verified (Beds24 API unavailable).";
            $lines[] = "    Booking was attempted anyway — check Beds24 to confirm.";
            $lines[] = '';
        }

        $lines[] = "Guest:     {$request->guestName}";

        if ($request->guestPhone !== '') {
            $lines[] = "Phone:     {$request->guestPhone}";
        }
        if ($request->guestEmail !== '') {
            $lines[] = "Email:     {$request->guestEmail}";
        }

        $lines[] = "Check-in:  {$request->checkIn}";
        $lines[] = "Check-out: {$request->checkOut}";
        $lines[] = '';

        foreach ($successes as $s) {
            $lines[] = "✅ Unit {$s['unit']} ({$s['room']}) — Booking #{$s['bookingId']}";
        }

        foreach ($failures as $f) {
            $lines[] = "❌ Unit {$f['unit']} — Failed: {$f['error']}";
        }

        // Finance / charge status line (only when a quoted total was provided)
        if ($chargeStatus !== ChargeWriteStatus::None) {
            $total      = $request->finance ? number_format($request->finance->quotedTotal, 2) : '0.00';
            $chargeLine = match ($chargeStatus) {
                ChargeWriteStatus::Written => "💳 Charge: \${$total} written to Beds24",
                ChargeWriteStatus::SkippedFeatureDisabled =>
                    "ℹ️  Quoted: \${$total} — charge recording is off, add manually in Beds24",
                ChargeWriteStatus::SkippedMultiRoomUnsupported =>
                    "ℹ️  Quoted: \${$total} — multi-room total, add charge per room manually",
                ChargeWriteStatus::Failed =>
                    "⚠️  Charge write failed — add \${$total} manually in Beds24",
                default => null,
            };

            if ($chargeLine !== null) {
                $lines[] = '';
                $lines[] = $chargeLine;
            }
        }

        if (!empty($failures) && !empty($successes)) {
            $createdIds = implode(', ', array_map(fn($s) => "#{$s['bookingId']}", $successes));
            $lines[]    = '';
            $lines[]    = "⚠️  Some rooms were created, some failed.";
            $lines[]    = "    Confirmed booking IDs: {$createdIds}";
            $lines[]    = "    To cancel a confirmed room: cancel booking #[ID]";
            $lines[]    = "    Retry failed rooms separately after cancelling if needed.";
        }

        return implode("\n", $lines);
    }
}
