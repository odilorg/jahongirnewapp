<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Finalized charge resolution for a bot-created booking.
 *
 * Produced by ResolveBotBookingChargeAction. Consumed by
 * BuildBeds24BookingPayloadAction and the operator confirmation
 * formatter. Immutable by construction.
 *
 * When hasCharge=false, every other money/currency/source field is null
 * and must not be read. Use ::none() for that case so the invariant is
 * enforced in one place.
 */
final readonly class ResolvedBotBookingChargeData
{
    public function __construct(
        public bool    $hasCharge,
        public int     $nights,
        public ?float  $pricePerNight,
        public ?float  $totalAmount,
        public ?string $currency,
        public ?string $source,        // 'manual' | 'auto' | null
        public string  $description = 'Room charge',
    ) {}

    public static function none(int $nights): self
    {
        return new self(false, $nights, null, null, null, null);
    }
}
