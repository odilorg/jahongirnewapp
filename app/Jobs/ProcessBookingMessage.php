<?php

namespace App\Jobs;

use App\Models\AuthorizedStaff;
use App\Models\RoomUnitMapping;
use App\Services\Beds24BookingService;
use App\Services\BookingIntentParser;
use App\Services\StaffAuthorizationService;
use App\Services\TelegramBotService;
use App\Services\StaffResponseFormatter;
use App\Services\TelegramKeyboardService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessBookingMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public array $update
    ) {}

    public function handle(
        StaffAuthorizationService $authService,
        BookingIntentParser $parser,
        TelegramBotService $telegram,
        StaffResponseFormatter $formatter,
        Beds24BookingService $beds24,
        TelegramKeyboardService $keyboard
    ): void {
        try {
            // Handle callback queries (button presses)
            if (isset($this->update['callback_query'])) {
                $this->handleCallbackQuery($this->update['callback_query'], $authService, $telegram, $beds24, $keyboard);
                return;
            }

            $message = $this->update['message'] ?? null;

            if (!$message) {
                return;
            }

            $chatId = $message['chat']['id'];
            $messageId = $message['message_id'];
            $text = $message['text'] ?? '';

            // Check for phone contact shared
            if (isset($message['contact'])) {
                $this->handlePhoneContact($message, $authService, $telegram, $formatter);
                return;
            }

            // Check authorization
            $staff = $authService->verifyTelegramUser($this->update);

            if (!$staff) {
                $telegram->sendMessage($chatId, $authService->getAuthorizationRequestMessage(), [
                    'reply_markup' => json_encode([
                        'keyboard' => [[
                            ['text' => 'Share Phone Number', 'request_contact' => true]
                        ]],
                        'one_time_keyboard' => true,
                        'resize_keyboard' => true
                    ])
                ]);
                return;
            }

            // Handle help command - show main menu with buttons
            if (in_array(strtolower($text), ['help', '/help', '/start', 'menu'])) {
                $welcomeMessage = "🏨 Booking Bot Menu\n\n" .
                                "Choose an option below or type your command:";

                $telegram->sendMessage($chatId, $welcomeMessage, [
                    'reply_markup' => $keyboard->formatForApi($keyboard->getMainMenu())
                ]);
                return;
            }

            // Parse intent with OpenAI
            $parsed = $parser->parse($text);

            // Handle command
            $response = $this->handleCommand($parsed, $staff, $beds24);

            $telegram->sendMessage($chatId, $response);

        } catch (\Exception $e) {
            Log::error('Process Booking Message Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'update' => $this->update
            ]);

            if (isset($chatId) && isset($telegram)) {
                $telegram->sendMessage($chatId, 'Error: ' . $e->getMessage());
            }
        }
    }

    protected function handlePhoneContact($message, $authService, $telegram, $formatter): void
    {
        $contact = $message['contact'];
        $phoneNumber = $contact['phone_number'];
        $chatId = $message['chat']['id'];
        $userId = $message['from']['id'];
        $username = $message['from']['username'] ?? '';

        $staff = $authService->linkPhoneNumber($phoneNumber, $userId, $username);

        if ($staff) {
            $telegram->sendMessage($chatId, $authService->getAccessGrantedMessage($staff));
        } else {
            $telegram->sendMessage($chatId, $authService->getAccessDeniedMessage($phoneNumber));
        }
    }

    protected function handleCommand($parsed, $staff, $beds24): string
    {
        $intent = $parsed['intent'] ?? 'unknown';

        switch ($intent) {
            case 'check_availability':
                return $this->handleCheckAvailability($parsed, $beds24);

            case 'create_booking':
                return $this->handleCreateBooking($parsed, $staff, $beds24);

            case 'view_bookings':
                return $this->handleViewBookings($parsed, $beds24);

            case 'modify_booking':
                return $this->handleModifyBooking($parsed, $staff, $beds24);

            case 'cancel_booking':
                return $this->handleCancelBooking($parsed, $staff, $beds24);

            default:
                return "I did not quite understand that. Try:\n\n" .
                       "- check avail jan 2-3\n" .
                       "- book room 12 under John Walker jan 2-3 tel +1234567890 email ok@ok.com\n" .
                       "- cancel booking #123456\n" .
                       "- help";
        }
    }

    protected function handleCheckAvailability(array $parsed, $beds24): string
    {
        $dates = $parsed['dates'] ?? null;

        if (!$dates || empty($dates['check_in']) || empty($dates['check_out'])) {
            return 'Please provide valid dates. Example: check avail jan 2-3';
        }

        $checkIn = $dates['check_in'];
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
            $availability = $beds24->checkAvailability($checkIn, $checkOut, $propertyIds);

            if (!$availability['success']) {
                throw new \Exception($availability['error'] ?? 'API request failed');
            }

            $availableRoomsApi = $availability['availableRooms'] ?? [];
            $nights = $availability['nights'] ?? [];
            $nightCount = count($nights);

            // Map API room data to our room units
            $availableUnits = [];
            foreach ($availableRoomsApi as $apiRoom) {
                $roomId = $apiRoom['roomId'];
                $quantity = $apiRoom['quantity'];
                $roomName = $apiRoom['roomName'];
                $propertyId = $apiRoom['propertyId'];

                // Find units for this room type - only take as many as available quantity
                $units = $rooms->where('room_id', $roomId)
                                ->where('property_id', $propertyId)
                                ->sortBy('unit_name')
                                ->take($quantity); // Only take N units where N = quantity available

                foreach ($units as $unit) {
                    $availableUnits[] = [
                        'unit' => $unit,
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
            $checkInDt = new \DateTimeImmutable($checkIn);
            $checkOutDt = new \DateTimeImmutable($checkOut);
            $monthName = $checkInDt->format('M');
            $startDay = $checkInDt->format('j');
            $endDay = $checkOutDt->format('j');
            $dateRange = "{$monthName} {$startDay}–{$endDay}";

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
            $byProperty = collect($availableUnits)->groupBy(function($item) {
                return $item['unit']->property_name;
            });

            foreach ($byProperty as $propertyName => $propertyUnits) {
                $response .= "━━━━━ " . strtoupper($propertyName) . " ━━━━━\n\n";

                // Group by room type
                $byRoomType = $propertyUnits->groupBy('roomName');

                foreach ($byRoomType as $roomTypeName => $typeUnits) {
                    $totalQty = $typeUnits->first()['quantity'];
                    $units = $typeUnits->pluck('unit');

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

    protected function handleCreateBooking(array $parsed, $staff, $beds24): string
    {
        $room = $parsed['room'] ?? null;
        $guest = $parsed['guest'] ?? null;
        $dates = $parsed['dates'] ?? null;

        if (!$room || empty($room['unit_name'])) {
            return 'Please specify a room. Example: book room 12 under...';
        }

        if (!$guest || empty($guest['name'])) {
            return 'Please provide guest name. Example: ...under John Walker...';
        }

        if (!$dates || empty($dates['check_in']) || empty($dates['check_out'])) {
            return 'Please provide check-in and check-out dates.';
        }

        $unitName = $room['unit_name'];
        $propertyHint = $parsed['property'] ?? null;

        // Build query for room lookup
        $query = RoomUnitMapping::where('unit_name', $unitName);

        // Apply property filter if specified
        if ($propertyHint) {
            if (stripos($propertyHint, 'premium') !== false) {
                $query->where('property_id', '172793'); // Jahongir Premium
            } elseif (stripos($propertyHint, 'hotel') !== false) {
                $query->where('property_id', '41097'); // Jahongir Hotel
            }
        }

        $matchingRooms = $query->get();

        if ($matchingRooms->isEmpty()) {
            return 'Room ' . $unitName . ' not found. Please check the room number and try again.';
        }

        // If multiple rooms with same unit name, need to disambiguate by property
        if ($matchingRooms->count() > 1) {
            $propertyList = $matchingRooms->map(function($r) {
                return $r->property_name . ' (Unit ' . $r->unit_name . ' - ' . $r->room_name . ')';
            })->join("\n");

            return "Multiple rooms found with unit number {$unitName}:\n\n" .
                   $propertyList . "\n\n" .
                   "Please specify the property in your booking command.\n" .
                   "Example: book room {$unitName} at Premium under [NAME]...\n" .
                   "Or: book room {$unitName} at Hotel under [NAME]...";
        }

        $roomMapping = $matchingRooms->first();

        $guestName = $guest['name'];
        $phone = $guest['phone'] ?? '';
        $email = $guest['email'] ?? '';
        $checkIn = $dates['check_in'];
        $checkOut = $dates['check_out'];

        try {
            $bookingData = [
                'property_id' => $roomMapping->property_id,
                'room_id' => $roomMapping->room_id,
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'guest_name' => $guestName,
                'guest_phone' => $phone,
                'guest_email' => $email,
                'notes' => 'Created by ' . $staff->full_name . ' via Telegram Bot'
            ];

            Log::info('Creating Beds24 booking', ['data' => $bookingData]);

            $result = $beds24->createBooking($bookingData);

            if (isset($result['success']) && $result['success']) {
                $bookingId = $result['bookId'] ?? 'Unknown';

                return "Booking Created Successfully!\n" .
                       "Booking ID: #{$bookingId}\n" .
                       "Room: {$roomMapping->unit_name} ({$roomMapping->room_name})\n" .
                       "Guest: {$guestName}\n" .
                       "Phone: {$phone}\n" .
                       "Email: {$email}\n" .
                       "Check-in: {$checkIn}\n" .
                       "Check-out: {$checkOut}\n\n" .
                       "Booking confirmed in Beds24!";
            } else {
                throw new \Exception('Booking creation failed: ' . json_encode($result));
            }

        } catch (\Exception $e) {
            Log::error('Booking creation failed', [
                'error' => $e->getMessage(),
                'data' => $bookingData ?? []
            ]);

            return "Booking Failed\n" .
                   "Room: {$unitName}\n" .
                   "Guest: {$guestName}\n" .
                   "Dates: {$checkIn} to {$checkOut}\n\n" .
                   "Error: {$e->getMessage()}\n\n" .
                   "Please check the details and try again or create manually in Beds24.";
        }
    }


    protected function handleViewBookings(array $parsed, $beds24): string
    {
        try {
            // Build filters based on parsed intent
            $filters = [];
            $filterType = $parsed['filter_type'] ?? null;

            // Get all configured room properties
            $rooms = RoomUnitMapping::all();
            $propertyIds = $rooms->pluck('property_id')->unique()->toArray();
            $filters['propertyId'] = $propertyIds;

            // Determine filter type from parsed data
            if ($filterType) {
                switch ($filterType) {
                    case 'arrivals_today':
                        $today = date('Y-m-d');
                        $filters['arrivalFrom'] = $today;
                        $filters['arrivalTo'] = $today;
                        $title = "Arrivals Today (" . date('M j, Y') . ")";
                        break;

                    case 'departures_today':
                        $today = date('Y-m-d');
                        $filters['departureFrom'] = $today;
                        $filters['departureTo'] = $today;
                        $title = "Departures Today (" . date('M j, Y') . ")";
                        break;

                    case 'current':
                        // In-house guests: arrival before or on today, departure after today
                        $today = date('Y-m-d');
                        $filters['arrivalTo'] = $today;
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
            $result = $beds24->getBookings($filters);

            Log::info('Bookings result', [
                'success' => $result['success'] ?? false,
                'count' => $result['count'] ?? 0,
                'has_data' => isset($result['data']),
                'data_empty' => empty($result['data'])
            ]);

            if (!isset($result['data']) || empty($result['data'])) {
                return "📭 No Bookings Found\n\n" .
                       "Filter: {$title}\n" .
                       "Date: " . date('M j, Y') . "\n\n" .
                       "No bookings match your search criteria.";
            }

            $bookings = $result['data'];
            $count = count($bookings);

            // Build response
            $response = "{$title}\n";
            $response .= "━━━━━━━━━━━━━━━━━━━━\n";
            $response .= "Found {$count} " . ($count == 1 ? 'booking' : 'bookings') . "\n\n";

            // Limit to first 10 bookings to avoid message length issues
            $displayCount = min($count, 10);

            for ($i = 0; $i < $displayCount; $i++) {
                $booking = $bookings[$i];

                $response .= "#{$booking['id']}\n";
                $response .= "Guest: " . ($booking['guestName'] ?? 'N/A') . "\n";
                $response .= "Room: " . ($booking['roomName'] ?? 'N/A') . "\n";
                $response .= "Dates: " . ($booking['arrival'] ?? 'N/A') . " → " . ($booking['departure'] ?? 'N/A') . "\n";

                if (isset($booking['status'])) {
                    $statusEmoji = match($booking['status']) {
                        'confirmed' => '✅',
                        'request' => '❓',
                        'cancelled' => '❌',
                        'new' => '🆕',
                        default => '•'
                    };
                    $response .= "Status: {$statusEmoji} " . ucfirst($booking['status']) . "\n";
                }

                if (isset($booking['numAdult']) || isset($booking['numChild'])) {
                    $adults = $booking['numAdult'] ?? 0;
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
                'error' => $e->getMessage(),
                'parsed' => $parsed
            ]);

            return "Error fetching bookings: " . $e->getMessage() . "\n\n" .
                   "Please try again or contact support.";
        }
    }

    protected function handleCancelBooking(array $parsed, $staff, $beds24): string
    {
        // Extract booking ID from parsed data
        $bookingId = $parsed['booking_id'] ?? null;

        // If not in parsed data, try to extract from raw text (fallback)
        if (!$bookingId && isset($parsed['_raw_message'])) {
            // Try to extract booking ID from message like "cancel booking #123456" or "cancel 123456"
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
                $getResult = $beds24->getBooking($bookingId);
                if (isset($getResult['data']) && !empty($getResult['data'])) {
                    $bookingDetails = $getResult['data'][0] ?? $getResult['data'];
                }
            } catch (\Exception $e) {
                Log::warning('Could not fetch booking details', ['error' => $e->getMessage()]);
                // Continue with cancellation even if we can't get details
            }

            // Cancel the booking
            Log::info('Cancelling booking', [
                'booking_id' => $bookingId,
                'staff' => $staff->full_name
            ]);

            $reason = 'Cancelled by ' . $staff->full_name . ' via Telegram Bot';
            $result = $beds24->cancelBooking($bookingId, $reason);

            Log::info('Cancel booking API response', ['result' => $result]);

            // Check if cancellation was successful
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
                $response = "✅ Booking Cancelled Successfully\n\n";
                $response .= "Booking ID: #{$bookingId}\n";

                // Add booking details if we got them
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
                // Check for specific error messages
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
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return "❌ Booking Cancellation Failed\n\n" .
                   "Booking ID: #{$bookingId}\n" .
                   "Error: {$e->getMessage()}\n\n" .
                   "Please check the booking ID and try again, or cancel manually in Beds24.";
        }
    }
    protected function handleModifyBooking(array $parsed, $staff, $beds24): string
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
            // First, get the current booking details
            Log::info('Fetching booking details for modification', ['booking_id' => $bookingId]);

            $getResult = $beds24->getBooking($bookingId);

            if (!isset($getResult['data']) || empty($getResult['data'])) {
                return "❌ Booking Not Found\n\n" .
                       "Booking ID: #{$bookingId}\n" .
                       "Could not find this booking. Please check the ID and try again.";
            }

            $currentBooking = $getResult['data'][0] ?? $getResult['data'];

            // Build the changes array
            $changes = [];
            $changesSummary = [];

            // Check for date changes
            $dates = $parsed['dates'] ?? null;
            if ($dates) {
                if (!empty($dates['check_in'])) {
                    $changes['arrival'] = $dates['check_in'];
                    $changesSummary[] = "Check-in: " . ($currentBooking['arrival'] ?? 'N/A') . " → " . $dates['check_in'];
                }
                if (!empty($dates['check_out'])) {
                    $changes['departure'] = $dates['check_out'];
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
                    $changes['mobile'] = $guest['phone'];
                    $changesSummary[] = "Phone: " . ($currentBooking['mobile'] ?? 'N/A') . " → " . $guest['phone'];
                }
                if (!empty($guest['email'])) {
                    $changes['email'] = $guest['email'];
                    $changesSummary[] = "Email: " . ($currentBooking['email'] ?? 'N/A') . " → " . $guest['email'];
                }
            }

            // Check for room change
            $room = $parsed['room'] ?? null;
            if ($room && !empty($room['unit_name'])) {
                $unitName = $room['unit_name'];
                $propertyHint = $parsed['property'] ?? null;

                // Build query for room lookup
                $query = RoomUnitMapping::where('unit_name', $unitName);

                // Apply property filter if specified
                if ($propertyHint) {
                    if (stripos($propertyHint, 'premium') !== false) {
                        $query->where('property_id', '172793');
                    } elseif (stripos($propertyHint, 'hotel') !== false) {
                        $query->where('property_id', '41097');
                    }
                }

                $matchingRooms = $query->get();

                if ($matchingRooms->isEmpty()) {
                    return "❌ Modification Failed\n\n" .
                           "Room {$unitName} not found. Please check the room number.";
                }

                if ($matchingRooms->count() > 1) {
                    $propertyList = $matchingRooms->map(function($r) {
                        return $r->property_name . ' (Unit ' . $r->unit_name . ')';
                    })->join("\n");

                    return "Multiple rooms found with unit {$unitName}:\n\n" .
                           $propertyList . "\n\n" .
                           "Please specify the property.\n" .
                           "Example: change booking #{$bookingId} to room {$unitName} at Premium";
                }

                $roomMapping = $matchingRooms->first();
                $changes['roomId'] = (int) $roomMapping->room_id;
                $changesSummary[] = "Room: " . ($currentBooking['roomName'] ?? 'N/A') . " → Unit {$unitName} ({$roomMapping->room_name})";
            }

            // Check if there are any changes
            if (empty($changes)) {
                return "No changes detected.\n\n" .
                       "Please specify what you want to modify:\n" .
                       "- Dates: change booking #{$bookingId} to jan 5-7\n" .
                       "- Guest name: modify booking #{$bookingId} guest name to Jane Smith\n" .
                       "- Phone: update booking #{$bookingId} phone to +998123456789\n" .
                       "- Room: change booking #{$bookingId} to room 14";
            }

            // If changing dates, validate availability (optional but recommended)
            if (isset($changes['arrival']) || isset($changes['departure'])) {
                $newArrival = $changes['arrival'] ?? $currentBooking['arrival'];
                $newDeparture = $changes['departure'] ?? $currentBooking['departure'];

                // Basic date validation
                if ($newArrival >= $newDeparture) {
                    return "❌ Invalid Dates\n\n" .
                           "Check-in date must be before check-out date.\n" .
                           "Requested: {$newArrival} to {$newDeparture}";
                }
            }

                // Check if dates are actually changing - prevent overbooking
                $currentArrival = $currentBooking['arrival'];
                $currentDeparture = $currentBooking['departure'];
                $datesChanged = ($newArrival != $currentArrival) || ($newDeparture != $currentDeparture);

                if ($datesChanged) {
                    $roomId = $currentBooking['roomId'] ?? null;
                    $propertyId = $currentBooking['propertyId'] ?? null;

                    if ($roomId && $propertyId) {
                        try {
                            Log::info('Checking room availability for date change', [
                                'booking_id' => $bookingId,
                                'room_id' => $roomId,
                                'current' => [$currentArrival, $currentDeparture],
                                'new' => [$newArrival, $newDeparture]
                            ]);

                            $availability = $beds24->checkAvailability($newArrival, $newDeparture, [$propertyId]);

                            if ($availability['success']) {
                                $availableRooms = $availability['availableRooms'] ?? [];
                                $roomAvailable = false;

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
                                           "Requested: {$newArrival} to {$newDeparture}\n\n" .
                                           "This room is booked by another guest during the new period.\n" .
                                           "Please choose different dates or cancel and rebook.";
                                }

                                Log::info('Room available - proceeding with modification');
                            }
                        } catch (\Exception $e) {
                            Log::warning('Availability check failed: ' . $e->getMessage());
                        }
                    }
                }


            // Perform the modification
            Log::info('Modifying booking', [
                'booking_id' => $bookingId,
                'changes' => $changes,
                'staff' => $staff->full_name
            ]);

            $result = $beds24->modifyBooking($bookingId, $changes);

            Log::info('Modify booking API response', ['result' => $result]);

            // Check if modification was successful
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
                $response = "✅ Booking Modified Successfully\n\n";
                $response .= "Booking ID: #{$bookingId}\n\n";
                $response .= "Changes:\n";
                foreach ($changesSummary as $change) {
                    $response .= "  • {$change}\n";
                }
                $response .= "\n";

                // Add current booking info
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
                // Check for specific error messages
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
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return "❌ Booking Modification Failed\n\n" .
                   "Booking ID: #{$bookingId}\n" .
                   "Error: {$e->getMessage()}\n\n" .
                   "Please check the details and try again, or modify manually in Beds24.";
        }
    }

    protected function handleCallbackQuery($callbackQuery, $authService, $telegram, $beds24, $keyboard): void
    {
        $chatId = $callbackQuery['message']['chat']['id'];
        $messageId = $callbackQuery['message']['message_id'];
        $callbackData = $callbackQuery['data']; // Fixed: it's 'data' not 'callback_data'
        $callbackQueryId = $callbackQuery['id'];

        // Check authorization
        $staff = $authService->verifyTelegramUser(['callback_query' => $callbackQuery]);

        if (!$staff) {
            $telegram->answerCallbackQuery($callbackQueryId, [
                'text' => 'You are not authorized to use this bot.',
                'show_alert' => true
            ]);
            return;
        }

        // Answer the callback query immediately to remove loading state
        $telegram->answerCallbackQuery($callbackQueryId);

        // Handle different button actions
        switch ($callbackData) {
            case 'main_menu':
                $telegram->editMessageText($chatId, $messageId, "🏨 Booking Bot Menu\n\nChoose an option below:", [
                    'reply_markup' => $keyboard->formatForApi($keyboard->getMainMenu())
                ]);
                break;

            case 'view_arrivals_today':
                $response = $this->handleViewBookings(['filter_type' => 'arrivals_today'], $beds24);
                $telegram->editMessageText($chatId, $messageId, $response, [
                    'reply_markup' => $keyboard->formatForApi($keyboard->getBackButton())
                ]);
                break;

            case 'view_departures_today':
                $response = $this->handleViewBookings(['filter_type' => 'departures_today'], $beds24);
                $telegram->editMessageText($chatId, $messageId, $response, [
                    'reply_markup' => $keyboard->formatForApi($keyboard->getBackButton())
                ]);
                break;

            case 'view_current':
                $response = $this->handleViewBookings(['filter_type' => 'current'], $beds24);
                $telegram->editMessageText($chatId, $messageId, $response, [
                    'reply_markup' => $keyboard->formatForApi($keyboard->getBackButton())
                ]);
                break;

            case 'view_new':
                $response = $this->handleViewBookings(['filter_type' => 'new'], $beds24);
                $telegram->editMessageText($chatId, $messageId, $response, [
                    'reply_markup' => $keyboard->formatForApi($keyboard->getBackButton())
                ]);
                break;

            case 'search_guest':
            case 'check_availability':
            case 'create_booking':
            case 'modify_booking':
            case 'cancel_booking':
                $instructions = match($callbackData) {
                    'search_guest' => "Please type the guest name to search.\n\nExample: search for John Smith",
                    'check_availability' => "Please type dates to check availability.\n\nExample: check avail jan 15-17",
                    'create_booking' => "Please type booking details.\n\nExample: book room 12 under John Smith jan 15-17 tel +1234567890 email john@email.com",
                    'modify_booking' => "Please type booking ID and changes.\n\nExample: modify booking #123456 to jan 15-17",
                    'cancel_booking' => "Please type booking ID to cancel.\n\nExample: cancel booking #123456",
                };

                $telegram->editMessageText($chatId, $messageId, $instructions, [
                    'reply_markup' => $keyboard->formatForApi($keyboard->getBackButton())
                ]);
                break;

            default:
                $telegram->editMessageText($chatId, $messageId, "Unknown action. Please try again.", [
                    'reply_markup' => $keyboard->formatForApi($keyboard->getMainMenu())
                ]);
                break;
        }
    }

}