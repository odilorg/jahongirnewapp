<?php

namespace App\Jobs;

use App\Models\AuthorizedStaff;
use App\Models\RoomUnitMapping;
use App\Services\Beds24BookingService;
use App\Services\BookingIntentParser;
use App\Services\StaffAuthorizationService;
use App\Services\TelegramBotService;
use App\Services\StaffResponseFormatter;
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
        Beds24BookingService $beds24
    ): void {
        try {
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

            // Handle help command
            if (in_array(strtolower($text), ['help', '/help', '/start'])) {
                $telegram->sendMessage($chatId, $formatter->formatHelp());
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
                return 'Viewing bookings feature coming soon!';

            case 'cancel_booking':
                return 'Cancel booking feature coming soon!';

            default:
                return "I did not quite understand that. Try:\n\n" .
                       "- check avail jan 2-3\n" .
                       "- book room 12 under John Walker jan 2-3 tel +1234567890 email ok@ok.com\n" .
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

            // Check which rooms are booked (queries both properties)
            $availability = $beds24->checkAvailability($checkIn, $checkOut, $propertyIds);
            $unavailableRoomIds = $availability["unavailableRoomIds"] ?? [];

            // Filter out unavailable room types
            $availableRooms = $rooms->filter(function($room) use ($unavailableRoomIds) {
                return !in_array((string)$room->room_id, $unavailableRoomIds, true);
            });
            if ($availableRooms->isEmpty()) {
                return "No rooms available\n" .
                       "Check-in: {$checkIn}\n" .
                       "Check-out: {$checkOut}\n\n" .
                       "All rooms are booked for these dates.";
            }

            // Group rooms by property for better organization
            $roomsByProperty = $availableRooms->groupBy('property_name');

            $response = "Available Rooms\n" .
                        "Check-in: {$checkIn}\n" .
                        "Check-out: {$checkOut}\n" .
                        "Total: " . $availableRooms->count() . " rooms\n\n";

            foreach ($roomsByProperty as $propertyName => $propertyRooms) {
                $response .= "━━━━━ " . strtoupper($propertyName) . " ━━━━━\n\n";
                
                foreach ($propertyRooms as $room) {
                    $response .= "Room {$room->unit_name} - {$room->room_name}\n";
                    $response .= "Type: " . ucfirst($room->room_type) . " | Max: {$room->max_guests} guests\n";
                    if ($room->base_price > 0) {
                        $response .= "Price: $" . $room->base_price . "/night
";
                    }
                    $response .= "\n";
                }
            }

            $response .= "To book, use: book room [NUMBER] under [NAME] {$checkIn} to {$checkOut} tel [PHONE] email [EMAIL]";

            return $response;

        } catch (\Exception $e) {
            Log::error('Availability check failed', ['error' => $e->getMessage()]);

            // Fallback to showing all rooms if API fails
            $response = "Available Rooms (API unavailable)\n" .
                        "Check-in: {$checkIn}\n" .
                        "Check-out: {$checkOut}\n\n";

            foreach ($rooms as $room) {
                $response .= "Room {$room->unit_name} - {$room->room_name}\n";
                $response .= "Type: " . ucfirst($room->room_type) . "\n";
                $response .= "Price: \${$room->base_price}/night\n\n";
            }

            $response .= "To book, use: book room [NUMBER] under [NAME] {$checkIn} to {$checkOut} tel [PHONE] email [EMAIL]";

            return $response;
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
        $roomMapping = RoomUnitMapping::where('unit_name', $unitName)->first();

        if (!$roomMapping) {
            return 'Room ' . $unitName . ' not found. Available rooms: ' .
                   RoomUnitMapping::pluck('unit_name')->join(', ');
        }

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
}
