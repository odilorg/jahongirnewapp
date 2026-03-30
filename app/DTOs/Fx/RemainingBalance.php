<?php

namespace App\DTOs\Fx;

/**
 * Remaining amount owed after partial payments, expressed in USD.
 */
final class RemainingBalance
{
    public function __construct(
        public readonly string $beds24BookingId,
        public readonly float  $totalUsd,       // full booking amount
        public readonly float  $paidUsd,        // sum of drawer-truth payments so far
        public readonly float  $remainingUsd,   // totalUsd - paidUsd
    ) {}

    public function isFullyPaid(): bool
    {
        return $this->remainingUsd <= 0.0;
    }
}
