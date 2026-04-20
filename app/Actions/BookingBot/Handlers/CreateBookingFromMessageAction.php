<?php

namespace App\Actions\BookingBot\Handlers;

use App\Models\RoomUnitMapping;
use App\Models\User;
use App\Services\Beds24BookingService;
use Illuminate\Support\Facades\Log;

/**
 * Handles "create booking" intent from @j_booking_hotel_bot.
 *
 * The room-lookup query concern (unit_name + optional property hint) lives
 * on the RoomUnitMapping model via forUnit() / matchingPropertyHint() scopes
 * per principle 2. This Action just composes them.
 */
final class CreateBookingFromMessageAction
{
    public function __construct(
        private readonly Beds24BookingService $beds24,
    ) {}

    public function execute(array $parsed, User $staff): string
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

        $matchingRooms = RoomUnitMapping::query()
            ->forUnit($unitName)
            ->matchingPropertyHint($propertyHint)
            ->get();

        if ($matchingRooms->isEmpty()) {
            return 'Room ' . $unitName . ' not found. Please check the room number and try again.';
        }

        // Same unit_name exists in both properties — force the user to disambiguate.
        if ($matchingRooms->count() > 1) {
            $propertyList = $matchingRooms->map(function ($r) {
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
                'notes' => 'Created by ' . $staff->name . ' via Telegram Bot',
            ];

            Log::info('Creating Beds24 booking', ['data' => $bookingData]);

            $result = $this->beds24->createBooking($bookingData);

            if (isset($result['success']) && $result['success']) {
                // Beds24BookingService::createBooking() returns 'bookingId' (canonical)
                // with 'id' as an alias. The old handler read a non-existent 'bookId'
                // key, which is why the reply said "#Unknown" for every successful
                // booking. Fixed in-Action; not a refactor regression.
                $bookingId = $result['bookingId'] ?? $result['id'] ?? 'Unknown';

                return "Booking Created Successfully!\n" .
                       "Booking ID: #{$bookingId}\n" .
                       "Room: {$roomMapping->unit_name} ({$roomMapping->room_name})\n" .
                       "Guest: {$guestName}\n" .
                       "Phone: {$phone}\n" .
                       "Email: {$email}\n" .
                       "Check-in: {$checkIn}\n" .
                       "Check-out: {$checkOut}\n\n" .
                       "Booking confirmed in Beds24!";
            }

            throw new \Exception('Booking creation failed: ' . json_encode($result));

        } catch (\Exception $e) {
            Log::error('Booking creation failed', [
                'error' => $e->getMessage(),
                'data' => $bookingData ?? [],
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
