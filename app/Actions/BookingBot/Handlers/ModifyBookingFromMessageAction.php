<?php

namespace App\Actions\BookingBot\Handlers;

use App\Models\RoomUnitMapping;
use App\Models\User;
use App\Services\Beds24BookingService;
use Illuminate\Support\Facades\Log;

/**
 * Handles "modify booking" intent from @j_booking_hotel_bot.
 *
 * Pure extraction from ProcessBookingMessage::handleModifyBooking.
 * Behaviour must be byte-identical — the golden master asserts this.
 *
 * Design note — why this is one Action, not four:
 *
 * The plan (§4.7) originally proposed splitting this into an orchestrator +
 * three step-actions (dates / guest / room). A read of the source showed the
 * method is an **accumulator**, not a dispatcher: a single Telegram message
 * can modify dates + guest + room simultaneously. The three "gatherers"
 * contribute to a single $changes array that drives one Beds24 call. They
 * aren't independent steps and splitting them would create artificial
 * abstraction. Kept as one Action with private helpers that mirror the real
 * seams: collect → validate → apply → format. Plan §4.7 updated accordingly.
 *
 * Known preserved defects (fix in separate commit, not this one):
 *   1. RoomUnitMapping queried directly — P6-P7 concern, plan follow-up.
 *   2. Indentation/bracket bug in guardDateAvailability(): $newArrival and
 *      $newDeparture are defined inside the "has date changes" block but read
 *      outside it. For guest-only / room-only modifications this triggers a
 *      "Warning: Undefined variable" and causes an unnecessary (failing)
 *      availability check that's swallowed by the inner try/catch. Behaviour
 *      is preserved byte-for-byte here so this extraction stays behaviour-
 *      neutral; the fix is tracked as a separate ticket with its own test.
 */
final class ModifyBookingFromMessageAction
{
    public function __construct(
        private readonly Beds24BookingService $beds24,
    ) {}

    public function execute(array $parsed, User $staff): string
    {
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

            $changes = [];
            $changesSummary = [];

            $dateChanges = $this->collectDateChanges($parsed, $currentBooking);
            $changes = array_merge($changes, $dateChanges['changes']);
            $changesSummary = array_merge($changesSummary, $dateChanges['summary']);

            $guestChanges = $this->collectGuestChanges($parsed, $currentBooking);
            $changes = array_merge($changes, $guestChanges['changes']);
            $changesSummary = array_merge($changesSummary, $guestChanges['summary']);

            $roomChange = $this->collectRoomChange($parsed, $currentBooking, $bookingId);
            if (is_string($roomChange)) {
                // Room lookup failed (not found / ambiguous) — early-return reply.
                return $roomChange;
            }
            $changes = array_merge($changes, $roomChange['changes']);
            $changesSummary = array_merge($changesSummary, $roomChange['summary']);

            if (empty($changes)) {
                return "No changes detected.\n\n" .
                       "Please specify what you want to modify:\n" .
                       "- Dates: change booking #{$bookingId} to jan 5-7\n" .
                       "- Guest name: modify booking #{$bookingId} guest name to Jane Smith\n" .
                       "- Phone: update booking #{$bookingId} phone to +998123456789\n" .
                       "- Room: change booking #{$bookingId} to room 14";
            }

            $availabilityReply = $this->guardDateAvailability($changes, $currentBooking, $bookingId);
            if ($availabilityReply !== null) {
                return $availabilityReply;
            }

            Log::info('Modifying booking', [
                'booking_id' => $bookingId,
                'changes' => $changes,
                'staff' => $staff->name,
            ]);

            $result = $this->beds24->modifyBooking($bookingId, $changes);

            Log::info('Modify booking API response', ['result' => $result]);

            // Beds24 responses vary: sometimes {success: true}, sometimes an
            // array with success on the first element, sometimes success is
            // merely implied by absence of error.
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
                return $this->formatSuccessReply($bookingId, $changes, $changesSummary, $currentBooking);
            }

            $errorMsg = 'Unknown error';
            if (isset($result['error'])) {
                $errorMsg = $result['error'];
            } elseif (isset($result[0]['error'])) {
                $errorMsg = $result[0]['error'];
            }

            throw new \Exception($errorMsg);

        } catch (\Exception $e) {
            Log::error('Booking modification failed', [
                'booking_id' => $bookingId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return "❌ Booking Modification Failed\n\n" .
                   "Booking ID: #{$bookingId}\n" .
                   "Error: {$e->getMessage()}\n\n" .
                   "Please check the details and try again, or modify manually in Beds24.";
        }
    }

    /**
     * @return array{changes: array, summary: array}
     */
    private function collectDateChanges(array $parsed, array $currentBooking): array
    {
        $changes = [];
        $summary = [];

        $dates = $parsed['dates'] ?? null;
        if ($dates) {
            if (!empty($dates['check_in'])) {
                $changes['arrival'] = $dates['check_in'];
                $summary[] = "Check-in: " . ($currentBooking['arrival'] ?? 'N/A') . " → " . $dates['check_in'];
            }
            if (!empty($dates['check_out'])) {
                $changes['departure'] = $dates['check_out'];
                $summary[] = "Check-out: " . ($currentBooking['departure'] ?? 'N/A') . " → " . $dates['check_out'];
            }
        }

        return ['changes' => $changes, 'summary' => $summary];
    }

    /**
     * @return array{changes: array, summary: array}
     */
    private function collectGuestChanges(array $parsed, array $currentBooking): array
    {
        $changes = [];
        $summary = [];

        $guest = $parsed['guest'] ?? null;
        if ($guest) {
            if (!empty($guest['name'])) {
                // Beds24 stores first/last separately; the parser gives us a single string.
                $nameParts = explode(' ', $guest['name'], 2);
                $changes['firstName'] = $nameParts[0];
                if (isset($nameParts[1])) {
                    $changes['lastName'] = $nameParts[1];
                }
                $summary[] = "Guest: " . ($currentBooking['guestName'] ?? 'N/A') . " → " . $guest['name'];
            }
            if (!empty($guest['phone'])) {
                $changes['mobile'] = $guest['phone'];
                $summary[] = "Phone: " . ($currentBooking['mobile'] ?? 'N/A') . " → " . $guest['phone'];
            }
            if (!empty($guest['email'])) {
                $changes['email'] = $guest['email'];
                $summary[] = "Email: " . ($currentBooking['email'] ?? 'N/A') . " → " . $guest['email'];
            }
        }

        return ['changes' => $changes, 'summary' => $summary];
    }

    /**
     * Returns either:
     *   - an array{changes, summary} to merge into the modification payload, or
     *   - a string reply (room not found / ambiguous) to return immediately.
     *
     * @return array{changes: array, summary: array}|string
     */
    private function collectRoomChange(array $parsed, array $currentBooking, string $bookingId): array|string
    {
        $changes = [];
        $summary = [];

        $room = $parsed['room'] ?? null;
        if (!$room || empty($room['unit_name'])) {
            return ['changes' => $changes, 'summary' => $summary];
        }

        $unitName = $room['unit_name'];
        $propertyHint = $parsed['property'] ?? null;

        $query = RoomUnitMapping::where('unit_name', $unitName);

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
            $propertyList = $matchingRooms->map(function ($r) {
                return $r->property_name . ' (Unit ' . $r->unit_name . ')';
            })->join("\n");

            return "Multiple rooms found with unit {$unitName}:\n\n" .
                   $propertyList . "\n\n" .
                   "Please specify the property.\n" .
                   "Example: change booking #{$bookingId} to room {$unitName} at Premium";
        }

        $roomMapping = $matchingRooms->first();
        $changes['roomId'] = (int) $roomMapping->room_id;
        $summary[] = "Room: " . ($currentBooking['roomName'] ?? 'N/A') . " → Unit {$unitName} ({$roomMapping->room_name})";

        return ['changes' => $changes, 'summary' => $summary];
    }

    /**
     * Returns null if the modification should proceed, or a reply string for
     * an early-return (bad date order / room unavailable).
     *
     * IMPORTANT — preserved bug:
     * The "$newArrival != $currentArrival" comparison below executes even
     * when no date changes are present, because the closing brace on the
     * date-order check closes too early in the original source. For guest-
     * only / room-only modifications, $newArrival and $newDeparture are
     * undefined, which in PHP 8 yields "Warning: Undefined variable" and a
     * null value, forcing $datesChanged=true and triggering a useless
     * availability check that fails silently inside the inner try/catch.
     * This extraction reproduces that behaviour byte-for-byte. Fix in
     * dedicated follow-up commit with a regression test.
     */
    private function guardDateAvailability(array $changes, array $currentBooking, string $bookingId): ?string
    {
        if (isset($changes['arrival']) || isset($changes['departure'])) {
            $newArrival = $changes['arrival'] ?? $currentBooking['arrival'];
            $newDeparture = $changes['departure'] ?? $currentBooking['departure'];

            if ($newArrival >= $newDeparture) {
                return "❌ Invalid Dates\n\n" .
                       "Check-in date must be before check-out date.\n" .
                       "Requested: {$newArrival} to {$newDeparture}";
            }
        }

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

                            $availability = $this->beds24->checkAvailability($newArrival, $newDeparture, [$propertyId]);

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

        return null;
    }

    private function formatSuccessReply(string $bookingId, array $changes, array $changesSummary, array $currentBooking): string
    {
        $response = "✅ Booking Modified Successfully\n\n";
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
    }
}
