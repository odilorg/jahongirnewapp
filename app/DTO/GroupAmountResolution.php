<?php

namespace App\DTO;

/**
 * Result of GroupAwareCashierAmountResolver::resolve().
 *
 * Carries the correct USD amount to use for FX calculation, plus the group
 * context metadata needed for:
 *   (a) detecting incomplete sibling sync
 *   (b) persisting audit trail on the CashTransaction
 *   (c) duplicate-payment guard across sibling booking IDs
 *
 * Immutable by convention — do not mutate after construction.
 */
readonly class GroupAmountResolution
{
    public function __construct(
        /** USD amount to use for FX sync and cashier presentation */
        public float   $usdAmount,

        /** True when the booking has no group (single room) */
        public bool    $isSingleBooking,

        /** Beds24 master booking ID; null for standalone bookings */
        public ?string $effectiveMasterBookingId,

        /** Total group size from Beds24 bookingGroup.ids; null for standalone */
        public ?int    $groupSizeExpected,

        /** Number of sibling rows found locally at resolve-time */
        public ?int    $groupSizeLocal,

        /**
         * True when all expected siblings were available locally.
         * False when some siblings were fetched on-demand from Beds24 API.
         */
        public bool    $isGroupComplete,
    ) {}

    /** Convenience factory for a standalone (single-room) booking */
    public static function standalone(float $usdAmount): self
    {
        return new self(
            usdAmount:                $usdAmount,
            isSingleBooking:          true,
            effectiveMasterBookingId: null,
            groupSizeExpected:        null,
            groupSizeLocal:           null,
            isGroupComplete:          true,
        );
    }
}
