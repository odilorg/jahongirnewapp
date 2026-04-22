<?php

declare(strict_types=1);

namespace App\DTO;

use Carbon\CarbonImmutable;

/**
 * Normalized hotel-booking-bot input.
 *
 * Produced by CreateBookingFromMessageAction after room-unit resolution,
 * then passed to ResolveBotBookingChargeAction and
 * BuildBeds24BookingPayloadAction. Carries only raw input — no totals,
 * no transport payload concerns.
 *
 * inputCurrency is expected uppercase-normalized by the caller, or null.
 */
final readonly class BotBookingRequestData
{
    public function __construct(
        public int|string $propertyId,
        public int|string $roomId,
        public string     $arrival,      // Y-m-d
        public string     $departure,    // Y-m-d
        public string     $firstName,
        public string     $lastName,
        public ?string    $email,
        public ?string    $mobile,
        public int        $numAdult,
        public int        $numChild,
        public ?string    $notes,
        public ?float     $inputPricePerNight,
        public ?string    $inputCurrency,
    ) {}

    public function nights(): int
    {
        return (int) CarbonImmutable::parse($this->arrival)
            ->diffInDays(CarbonImmutable::parse($this->departure));
    }
}
