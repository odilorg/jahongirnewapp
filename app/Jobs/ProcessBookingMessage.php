<?php

namespace App\Jobs;

use App\Actions\BookingBot\Handlers\CancelBookingFromMessageAction;
use App\Actions\BookingBot\Handlers\CheckAvailabilityAction;
use App\Actions\BookingBot\Handlers\CreateBookingFromMessageAction;
use App\Actions\BookingBot\Handlers\HandleCallbackQueryAction;
use App\Actions\BookingBot\Handlers\HandlePhoneContactAction;
use App\Actions\BookingBot\Handlers\ViewBookingsFromMessageAction;
use App\Models\User;
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
                app(HandleCallbackQueryAction::class)->execute($this->update['callback_query']);
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
                app(HandlePhoneContactAction::class)->execute($message);
                return;
            }

            // Check authorization
            $staff = $authService->verifyTelegramUser($this->update);

            if (!$staff) {
                $telegram->sendMessage($chatId, $authService->getAuthorizationRequestMessage(), [
                    'reply_markup' => [
                        'keyboard' => [[
                            ['text' => '📱 Share Phone Number', 'request_contact' => true]
                        ]],
                        'one_time_keyboard' => true,
                        'resize_keyboard' => true
                    ]
                ]);
                return;
            }

            // Handle greetings and help — show main menu without hitting the AI parser
            $greetings = ['hi', 'hello', 'hey', 'hola', 'help', '/help', '/start', 'menu', 'привет', 'салам'];
            if (in_array(strtolower(trim($text)), $greetings)) {
                $welcomeMessage = "🏨 *Booking Bot*\n\nChoose an option or type your command:";

                $telegram->sendMessage($chatId, $welcomeMessage, [
                    'parse_mode' => 'Markdown',
                    'reply_markup' => $keyboard->formatForApi($keyboard->getMainMenu())
                ]);
                return;
            }

            // Parse intent with OpenAI
            $parsed = $parser->parse($text);

            // Handle command
            $response = $this->handleCommand($parsed, $staff, $beds24);

            // Send response with back button for view and check commands
            $intent = $parsed['intent'] ?? 'unknown';
            $needsBackButton = in_array($intent, ['view_bookings', 'check_availability']);

            if ($needsBackButton) {
                $telegram->sendMessage($chatId, $response, [
                    'reply_markup' => $keyboard->formatForApi($keyboard->getBackButton())
                ]);
            } else {
                $telegram->sendMessage($chatId, $response);
            }

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

    protected function handleCommand($parsed, $staff, $beds24): string
    {
        $intent = $parsed['intent'] ?? 'unknown';

        switch ($intent) {
            case 'check_availability':
                return app(CheckAvailabilityAction::class)->execute($parsed);

            case 'create_booking':
                return app(CreateBookingFromMessageAction::class)->execute($parsed, $staff);

            case 'view_bookings':
                return app(ViewBookingsFromMessageAction::class)->execute($parsed);

            case 'modify_booking':
                return $this->handleModifyBooking($parsed, $staff, $beds24);

            case 'cancel_booking':
                return app(CancelBookingFromMessageAction::class)->execute($parsed, $staff);

            default:
                return "I did not quite understand that. Try:\n\n" .
                       "- check avail jan 2-3\n" .
                       "- book room 12 under John Walker jan 2-3 tel +1234567890 email ok@ok.com\n" .
                       "- cancel booking #123456\n" .
                       "- help";
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
                'staff' => $staff->name
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

}