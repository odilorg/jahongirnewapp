<?php

declare(strict_types=1);

namespace App\Actions\BookingBot;

use App\DTO\BotBookingRequestData;
use App\DTO\ResolvedBotBookingChargeData;
use App\Models\RoomUnitMapping;
use Carbon\CarbonImmutable;

/**
 * Formats the English-only guest-forward text the operator copies out
 * of Telegram and sends to the guest via WhatsApp / email / SMS.
 *
 * Phase 8.1 — operator-forward mode. No auto-send. No markdown (plain
 * text renders identically on WhatsApp, email, Telegram, and SMS).
 *
 * Input is shared with the create-booking flow; output is a ready-to-copy
 * string. Config drives property-specific strings (address, maps link);
 * shared strings (phone, WA, check-in/out times) come from `defaults`.
 *
 * Returns empty string when the feature is disabled or when required
 * property config is missing — the caller must handle that as "don't
 * send a second message".
 */
final class FormatGuestConfirmationAction
{
    /**
     * @param list<RoomUnitMapping>  $rooms         One entry for single, N for group.
     * @param list<int|string>       $bookingIds    Master first, siblings after.
     */
    public function execute(
        BotBookingRequestData $data,
        ResolvedBotBookingChargeData $charge,
        array $rooms,
        array $bookingIds,
    ): string {
        if (! (bool) config('hotel_booking_bot.guest_confirmation.enabled', false)) {
            return '';
        }

        if (empty($rooms) || empty($bookingIds)) {
            return '';
        }

        $propertyId = (string) $rooms[0]->property_id;
        $property   = config('hotel_booking_bot.guest_confirmation.properties.' . $propertyId);
        if (!is_array($property)) {
            return '';
        }

        $defaults = (array) config('hotel_booking_bot.guest_confirmation.defaults', []);

        $firstName  = trim($data->firstName);
        $name       = (string) ($property['name_en'] ?? '');
        $address    = (string) ($property['address'] ?? '');
        $mapsLink   = (string) ($property['maps_link'] ?? '');
        $phone      = (string) ($defaults['phone'] ?? '');
        $whatsapp   = (string) ($defaults['whatsapp'] ?? '');
        $checkIn    = (string) ($defaults['check_in_time'] ?? '14:00');
        $checkOut   = (string) ($defaults['check_out_time'] ?? '12:00');

        $nights       = $charge->nights;
        $nightsWord   = $this->nightsWord($nights);
        $arrivalHuman = $this->humanDate($data->arrival);
        $departureHuman = $this->humanDate($data->departure);

        $roomsList = implode(', ', array_map(
            static fn (RoomUnitMapping $m) => $m->unit_name . ' — ' . $m->room_name,
            $rooms,
        ));

        $reference = '#' . implode(' / #', array_map(static fn ($id) => (string) $id, $bookingIds));

        $adults = $data->numAdult . ' ' . ($data->numAdult === 1 ? 'adult' : 'adults');

        $lines = [
            'Booking confirmation',
            '',
            "Hello, {$firstName}!",
            '',
            "Your reservation at {$name} is confirmed.",
            '',
            "Hotel: {$name}",
            "Dates: {$arrivalHuman} → {$departureHuman} ({$nights} {$nightsWord})",
            "Rooms: {$roomsList}",
            "Guests: {$adults}",
            "Reference: {$reference}",
        ];

        $chargeBlock = $this->chargeBlock($charge, count($rooms));
        if ($chargeBlock !== '') {
            $lines[] = '';
            $lines[] = $chargeBlock;
        }

        $lines = array_merge($lines, [
            '',
            "Check-in: from {$checkIn}",
            "Check-out: until {$checkOut}",
        ]);

        if ($address !== '') {
            $lines[] = "Address: {$address}";
        }
        if ($mapsLink !== '') {
            $lines[] = "Map: {$mapsLink}";
        }

        $lines = array_merge($lines, [
            '',
            'Need help?',
        ]);
        if ($phone !== '') {
            $lines[] = "Phone: {$phone}";
        }
        if ($whatsapp !== '') {
            $lines[] = "WhatsApp: {$whatsapp}";
        }

        $lines = array_merge($lines, [
            '',
            'See you soon!',
            "— {$name}",
        ]);

        return implode("\n", $lines);
    }

    private function chargeBlock(ResolvedBotBookingChargeData $charge, int $roomsCount): string
    {
        if (! $charge->hasCharge) {
            return '';
        }

        $price    = $this->money((float) $charge->pricePerNight);
        $perRoom  = $this->money((float) $charge->totalAmount);
        $groupTtl = $this->money((float) $charge->totalAmount * $roomsCount);
        $cur      = (string) $charge->currency;
        $nights   = $charge->nights;
        $nWord    = $this->nightsWord($nights);

        if ($roomsCount === 1) {
            return "Price: {$price} {$cur} per night × {$nights} {$nWord} = {$perRoom} {$cur}";
        }

        return "Price: {$price} {$cur} per room per night × {$nights} {$nWord}\n" .
               "Group total: {$groupTtl} {$cur}";
    }

    private function humanDate(string $ymd): string
    {
        try {
            return CarbonImmutable::parse($ymd)->format('D, j M Y');
        } catch (\Throwable) {
            return $ymd;
        }
    }

    private function nightsWord(int $nights): string
    {
        return $nights === 1 ? 'night' : 'nights';
    }

    private function money(float $amount): string
    {
        return number_format($amount, 2, '.', ' ');
    }
}
