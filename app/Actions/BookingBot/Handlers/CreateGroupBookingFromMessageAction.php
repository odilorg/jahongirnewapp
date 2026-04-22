<?php

declare(strict_types=1);

namespace App\Actions\BookingBot\Handlers;

use App\Actions\BookingBot\BuildBeds24BookingPayloadAction;
use App\Actions\BookingBot\ResolveBotBookingChargeAction;
use App\DTO\BotBookingRequestData;
use App\DTO\ResolvedBotBookingChargeData;
use App\Exceptions\BookingBot\BotBookingChargeResolutionException;
use App\Models\RoomUnitMapping;
use App\Models\User;
use App\Services\Beds24BookingService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Group ("create_booking" intent with $parsed['rooms']) handler for
 * @j_booking_hotel_bot.
 *
 * Locked v1 rules (see memory project_hotel_bot_charges):
 *   1. pricing       — per-room per-night (same rate applied to every room)
 *   2. ownership     — one guest shared across all rooms
 *   3. atomic create — any sibling failure → cancel all created siblings
 *                      and return operator error ("all rooms released")
 *   4. master        — first payload member in POST order
 *   5. duplicates    — reject if any unit appears twice
 *   6. same property — all rooms must share one property_id
 *
 * The single-booking path (handler branches to CreateBookingFromMessageAction
 * when $parsed['rooms'] is empty) is untouched.
 */
final class CreateGroupBookingFromMessageAction
{
    public function __construct(
        private readonly Beds24BookingService $beds24,
        private readonly ResolveBotBookingChargeAction $chargeResolver,
        private readonly BuildBeds24BookingPayloadAction $payloadBuilder,
    ) {}

    public function execute(array $parsed, User $staff): string
    {
        $rooms = $parsed['rooms'] ?? [];
        $guest = $parsed['guest'] ?? null;
        $dates = $parsed['dates'] ?? null;

        if (empty($rooms) || !is_array($rooms)) {
            return 'Please specify at least two rooms. Example: book rooms 12 and 14 under John Walker july 5-7 tel +998...';
        }

        if (!$guest || empty($guest['name'])) {
            return 'Please provide guest name. Example: ...under John Walker...';
        }

        if (!$dates || empty($dates['check_in']) || empty($dates['check_out'])) {
            return 'Please provide check-in and check-out dates.';
        }

        $units = array_map(
            static fn (array $r) => isset($r['unit_name']) ? (string) $r['unit_name'] : '',
            $rooms,
        );
        $units = array_values(array_filter($units, static fn ($u) => $u !== ''));

        if (count($units) < 2) {
            return 'Group booking requires at least two rooms. For a single room, drop the "rooms" list.';
        }

        $duplicates = array_keys(array_filter(array_count_values($units), static fn ($n) => $n > 1));
        if (!empty($duplicates)) {
            return 'Duplicate rooms detected: ' . implode(', ', $duplicates) . '. Please specify each room once.';
        }

        $resolvedRooms = [];
        foreach ($rooms as $roomData) {
            $unitName = isset($roomData['unit_name']) ? (string) $roomData['unit_name'] : '';
            if ($unitName === '') {
                continue;
            }

            $propertyHint = $roomData['property'] ?? ($parsed['property'] ?? null);

            $matches = RoomUnitMapping::query()
                ->forUnit($unitName)
                ->matchingPropertyHint($propertyHint)
                ->get();

            if ($matches->isEmpty()) {
                return "Room {$unitName} not found. Please check the room number and try again.";
            }

            if ($matches->count() > 1) {
                return "Room {$unitName} matches multiple properties. Please add 'at Premium' or 'at Hotel' to disambiguate.";
            }

            $resolvedRooms[] = $matches->first();
        }

        // Rule 6 — same property.
        $propertyIds = array_unique(array_map(
            static fn (RoomUnitMapping $m) => (string) $m->property_id,
            $resolvedRooms,
        ));
        if (count($propertyIds) > 1) {
            return 'Group bookings must all be at the same property. Please split the command into one per property.';
        }

        $guestName = (string) $guest['name'];
        $phone     = (string) ($guest['phone'] ?? '');
        $email     = (string) ($guest['email'] ?? '');
        $checkIn   = (string) $dates['check_in'];
        $checkOut  = (string) $dates['check_out'];

        $chargeInput = $parsed['charge'] ?? [];
        $inputPricePerNight = isset($chargeInput['price_per_night']) && $chargeInput['price_per_night'] !== ''
            ? (float) $chargeInput['price_per_night']
            : null;
        $inputCurrency = isset($chargeInput['currency']) && $chargeInput['currency'] !== ''
            ? strtoupper((string) $chargeInput['currency'])
            : null;

        $notes = 'Created by ' . $staff->name . ' via Telegram Bot (group)';

        $payloads    = [];
        $resolveds   = [];
        $perRoomList = [];

        try {
            foreach ($resolvedRooms as $mapping) {
                $data = new BotBookingRequestData(
                    propertyId:         $mapping->property_id,
                    roomId:             $mapping->room_id,
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

                $resolved  = $this->chargeResolver->execute($data);
                $payloads[]    = $this->payloadBuilder->execute($data, $resolved, $notes);
                $resolveds[]   = $resolved;
                $perRoomList[] = $mapping;
            }
        } catch (BotBookingChargeResolutionException $e) {
            Log::info('Booking bot: group charge resolution rejected', [
                'error' => $e->getMessage(),
                'staff' => $staff->id,
            ]);

            return "Could not create group booking: {$e->getMessage()}";
        }

        try {
            $result = $this->beds24->createMultipleBookingsFromPayloads($payloads, true);
        } catch (Throwable $e) {
            Log::error('Group booking creation failed', ['error' => $e->getMessage()]);
            return $this->failureMessage($perRoomList, $checkIn, $checkOut, $e->getMessage());
        }

        [$createdIds, $firstFailure] = $this->walkResult($result);

        if ($firstFailure !== null) {
            $this->rollback($createdIds);
            Log::warning('Booking bot: group partial failure rolled back', [
                'created_ids' => $createdIds,
                'failure'     => $firstFailure,
            ]);

            return "Group booking failed: {$firstFailure}. All rooms released.";
        }

        if (count($createdIds) !== count($payloads)) {
            // Defensive: shape not as expected. Roll back whatever we got.
            $this->rollback($createdIds);
            return 'Group booking failed: unexpected Beds24 response. All rooms released.';
        }

        $masterId = $createdIds[0];
        $siblings = array_slice($createdIds, 1);
        $firstCharge = $resolveds[0] ?? ResolvedBotBookingChargeData::none(1);

        return $this->successMessage(
            masterId:   $masterId,
            siblingIds: $siblings,
            rooms:      $perRoomList,
            guestName:  $guestName,
            phone:      $phone,
            email:      $email,
            checkIn:    $checkIn,
            checkOut:   $checkOut,
            charge:     $firstCharge,
        );
    }

    /**
     * @return array{0: array<int, int|string>, 1: ?string}
     */
    private function walkResult(array $result): array
    {
        $createdIds   = [];
        $firstFailure = null;

        foreach ($result as $r) {
            $ok = $r['success'] ?? false;
            if ($ok) {
                $id = $r['new']['id'] ?? $r['id'] ?? null;
                if ($id !== null) {
                    $createdIds[] = $id;
                }
                continue;
            }

            if ($firstFailure === null) {
                $firstFailure = isset($r['errors']) ? json_encode($r['errors']) : 'Beds24 rejected booking';
            }
        }

        return [$createdIds, $firstFailure];
    }

    /** @param array<int, int|string> $ids */
    private function rollback(array $ids): void
    {
        foreach ($ids as $id) {
            try {
                $this->beds24->cancelBooking((string) $id, 'Group rollback: sibling failed');
            } catch (Throwable $e) {
                // We log, but we must keep trying every id — we cannot leave
                // a partially-created group in production.
                Log::error('Group rollback: cancel failed — manual cleanup required', [
                    'booking_id' => $id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }
    }

    /** @param list<RoomUnitMapping> $rooms */
    private function failureMessage(array $rooms, string $checkIn, string $checkOut, string $error): string
    {
        $units = implode(', ', array_map(static fn (RoomUnitMapping $m) => $m->unit_name, $rooms));

        return "Group Booking Failed\n" .
               "Rooms: {$units}\n" .
               "Dates: {$checkIn} to {$checkOut}\n\n" .
               "Error: {$error}\n\n" .
               'Please check the details and try again or create manually in Beds24.';
    }

    /** @param list<int|string> $siblingIds @param list<RoomUnitMapping> $rooms */
    private function successMessage(
        int|string $masterId,
        array $siblingIds,
        array $rooms,
        string $guestName,
        string $phone,
        string $email,
        string $checkIn,
        string $checkOut,
        ResolvedBotBookingChargeData $charge,
    ): string {
        $unitList = implode(', ', array_map(
            static fn (RoomUnitMapping $m) => $m->unit_name . ' (' . $m->room_name . ')',
            $rooms,
        ));
        $allIds  = array_merge([$masterId], $siblingIds);
        $idsText = implode(', ', array_map(static fn ($i) => '#' . $i, $allIds));

        $totalsLine = $this->groupChargeLine($charge, count($rooms));

        return "Group Booking Created Successfully!\n" .
               "Master: #{$masterId}\n" .
               'Bookings: ' . $idsText . "\n" .
               "Rooms: {$unitList}\n" .
               "Guest: {$guestName}\n" .
               "Phone: {$phone}\n" .
               "Email: {$email}\n" .
               "Check-in: {$checkIn}\n" .
               "Check-out: {$checkOut}\n" .
               $totalsLine . "\n\n" .
               'All bookings confirmed in Beds24 as one group.';
    }

    private function groupChargeLine(ResolvedBotBookingChargeData $charge, int $roomsCount): string
    {
        if (!$charge->hasCharge) {
            return 'Charge: not added';
        }

        $price      = number_format((float) $charge->pricePerNight, 2, '.', ' ');
        $perRoom    = number_format((float) $charge->totalAmount, 2, '.', ' ');
        $groupTotal = number_format((float) $charge->totalAmount * $roomsCount, 2, '.', ' ');
        $cur        = (string) $charge->currency;
        $source     = $charge->source === 'auto' ? 'auto (room base price)' : 'manual';

        return "Charge: {$price} {$cur}/night × {$charge->nights} nights × {$roomsCount} rooms\n" .
               "Per room: {$perRoom} {$cur}   Group total: {$groupTotal} {$cur}\n" .
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
