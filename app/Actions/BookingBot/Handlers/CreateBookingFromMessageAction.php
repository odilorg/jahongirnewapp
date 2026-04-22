<?php

declare(strict_types=1);

namespace App\Actions\BookingBot\Handlers;

use App\Actions\BookingBot\BuildBeds24BookingPayloadAction;
use App\Actions\BookingBot\FormatGuestConfirmationAction;
use App\Actions\BookingBot\ResolveBotBookingChargeAction;
use App\Jobs\ProcessBookingMessage;
use App\DTO\BotBookingRequestData;
use App\DTO\ResolvedBotBookingChargeData;
use App\Exceptions\BookingBot\BotBookingChargeResolutionException;
use App\Models\RoomUnitMapping;
use App\Models\User;
use App\Services\Beds24BookingService;
use App\Support\BookingBot\LogSanitizer;
use Illuminate\Support\Facades\Log;

/**
 * Handles "create booking" intent from @j_booking_hotel_bot.
 *
 * The room-lookup query concern (unit_name + optional property hint) lives
 * on the RoomUnitMapping model via forUnit() / matchingPropertyHint() scopes
 * per principle 2. Charge resolution and payload construction are their
 * own Actions; this handler just orchestrates.
 */
final class CreateBookingFromMessageAction
{
    public function __construct(
        private readonly Beds24BookingService $beds24,
        private readonly ResolveBotBookingChargeAction $chargeResolver,
        private readonly BuildBeds24BookingPayloadAction $payloadBuilder,
        private readonly CreateGroupBookingFromMessageAction $groupAction,
        private readonly FormatGuestConfirmationAction $guestConfirmation,
    ) {}

    public function execute(array $parsed, User $staff): string
    {
        // Parser emits $parsed['rooms'] (plural) when the operator booked
        // multiple rooms in one command. Delegate to the group path — it
        // shares the same resolver + payload builder.
        $rooms = $parsed['rooms'] ?? null;
        if (is_array($rooms) && count($rooms) > 1) {
            return $this->groupAction->execute($parsed, $staff);
        }

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

        $guestName = (string) $guest['name'];
        $phone = (string) ($guest['phone'] ?? '');
        $email = (string) ($guest['email'] ?? '');
        $checkIn = (string) $dates['check_in'];
        $checkOut = (string) $dates['check_out'];

        $charge = $parsed['charge'] ?? [];
        $inputPricePerNight = isset($charge['price_per_night']) && $charge['price_per_night'] !== ''
            ? (float) $charge['price_per_night']
            : null;
        $inputCurrency = isset($charge['currency']) && $charge['currency'] !== ''
            ? strtoupper((string) $charge['currency'])
            : null;

        $notes = 'Created by ' . $staff->name . ' via Telegram Bot';

        $data = new BotBookingRequestData(
            propertyId:         $roomMapping->property_id,
            roomId:             $roomMapping->room_id,
            arrival:            $checkIn,
            departure:          $checkOut,
            firstName:          $this->firstName($guestName),
            lastName:           $this->lastName($guestName),
            email:              $email === '' ? null : $email,
            mobile:             $phone === '' ? null : $phone,
            numAdult:           2,
            numChild:           0,
            notes:              $notes,
            inputPricePerNight: $inputPricePerNight,
            inputCurrency:      $inputCurrency,
        );

        try {
            $resolvedCharge = $this->chargeResolver->execute($data);
        } catch (BotBookingChargeResolutionException $e) {
            Log::info('Booking bot: charge resolution rejected', [
                'error' => $e->getMessage(),
                'staff' => $staff->id,
            ]);

            return "Could not create booking: {$e->getMessage()}";
        }

        $payload = $this->payloadBuilder->execute($data, $resolvedCharge, $notes);

        try {
            Log::info('Creating Beds24 booking', LogSanitizer::context(['payload' => $payload]));

            $result = $this->beds24->createBookingFromPayload($payload);

            if (isset($result['success']) && $result['success']) {
                $bookingId = $result['bookingId'] ?? $result['id'] ?? 'Unknown';

                $operatorReceipt =
                    "Booking Created Successfully!\n" .
                    "Booking ID: #{$bookingId}\n" .
                    "Room: {$roomMapping->unit_name} ({$roomMapping->room_name})\n" .
                    "Guest: {$guestName}\n" .
                    "Phone: {$phone}\n" .
                    "Email: {$email}\n" .
                    "Check-in: {$checkIn}\n" .
                    "Check-out: {$checkOut}\n" .
                    $this->chargeLine($resolvedCharge) . "\n\n" .
                    "Booking confirmed in Beds24!";

                $guestText = $this->guestConfirmation->execute(
                    $data,
                    $resolvedCharge,
                    [$roomMapping],
                    [$bookingId],
                );

                return $guestText === ''
                    ? $operatorReceipt
                    : $operatorReceipt . ProcessBookingMessage::GUEST_FORWARD_MARKER . $guestText;
            }

            throw new \Exception('Booking creation failed: ' . json_encode($result));

        } catch (\Exception $e) {
            Log::error('Booking creation failed', LogSanitizer::context([
                'error' => $e->getMessage(),
                'payload' => $payload ?? [],
            ]));

            return "Booking Failed\n" .
                   "Room: {$unitName}\n" .
                   "Guest: {$guestName}\n" .
                   "Dates: {$checkIn} to {$checkOut}\n\n" .
                   "Error: {$e->getMessage()}\n\n" .
                   "Please check the details and try again or create manually in Beds24.";
        }
    }

    private function chargeLine(ResolvedBotBookingChargeData $charge): string
    {
        if (! $charge->hasCharge) {
            return 'Charge: not added';
        }

        $price  = number_format((float) $charge->pricePerNight, 2, '.', ' ');
        $total  = number_format((float) $charge->totalAmount, 2, '.', ' ');
        $cur    = (string) $charge->currency;
        $source = $charge->source === 'auto' ? 'auto (room base price)' : 'manual';

        return "Charge: {$price} {$cur}/night × {$charge->nights} nights = {$total} {$cur}\n" .
               "Source: {$source}";
    }

    private function firstName(string $fullName): string
    {
        $parts = explode(' ', trim($fullName), 2);
        return $parts[0] ?? $fullName;
    }

    private function lastName(string $fullName): string
    {
        $parts = explode(' ', trim($fullName), 2);
        return $parts[1] ?? ($parts[0] ?? '');
    }
}
