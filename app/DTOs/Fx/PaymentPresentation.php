<?php

namespace App\DTOs\Fx;

use App\Enums\Currency;
use Carbon\Carbon;

/**
 * Frozen snapshot of calculated amounts shown to the cashier.
 * The bot must never recalculate on its own — it works from this DTO only.
 * Once shown to the cashier, this presentation is immutable for the session.
 */
final class PaymentPresentation
{
    public function __construct(
        public readonly string   $beds24BookingId,
        public readonly string   $guestName,
        public readonly string   $roomNumber,

        // Full amounts in every supported currency
        public readonly int      $uzsAmount,
        public readonly float    $eurAmount,
        public readonly float    $rubAmount,
        public readonly float    $usdAmount,

        // The USD booking amount this was calculated from
        public readonly float    $usdBookingAmount,

        // The exchange rate row ID used (for audit trail)
        public readonly int      $exchangeRateId,

        // The rate effective date (matches booking_fx_syncs.fx_rate_date)
        public readonly Carbon   $rateDate,

        // Timestamp at which this presentation was prepared — used for TTL check
        public readonly Carbon   $preparedAt,

        // Whether admin has printed the registration form with this snapshot
        public readonly bool     $isPrinted,
    ) {}

    /**
     * Whether this presentation is still within the allowed TTL window.
     * After TTL, the cashier must restart the flow to get fresh rates.
     */
    public function isStale(): bool
    {
        $ttlMinutes = (int) config('fx.presentation_ttl_minutes', 30);

        return $this->preparedAt->diffInMinutes(now()) >= $ttlMinutes;
    }

    /**
     * The displayed amount for a given currency.
     */
    public function amountFor(Currency $currency): int|float
    {
        return match ($currency) {
            Currency::UZS => $this->uzsAmount,
            Currency::EUR => $this->eurAmount,
            Currency::RUB => $this->rubAmount,
            Currency::USD => $this->usdAmount,
        };
    }
}
