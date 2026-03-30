<?php

namespace App\DTO;

use App\Models\Beds24Booking;
use App\Models\BookingFxSync;
use Carbon\Carbon;

/**
 * Immutable snapshot of what the cashier bot showed the cashier.
 *
 * Created once in BotPaymentService::preparePayment() and frozen.
 * Held in the bot's conversation state (TelegramPosSession data).
 * recordPayment() consumes this DTO — it never re-reads the live sync row.
 *
 * This guarantees that even if a webhook refreshes booking_fx_syncs during
 * a long conversation, the cashier records against what they actually saw.
 */
readonly class PaymentPresentation
{
    /** Bot will reject confirmations after this many minutes. */
    public const TTL_MINUTES = 20;

    public function __construct(
        // Use beds24_booking_id (external Beds24 ID), not local model PK
        public string $beds24BookingId,
        public int    $syncId,
        public int    $dailyExchangeRateId,
        public string $guestName,
        public string $arrivalDate,
        public int    $uzsPresented,
        public int    $eurPresented,
        public int    $rubPresented,
        public string $fxRateDate,         // formatted: "28.03.2026"
        public string $botSessionId,
        public Carbon $presentedAt,
    ) {}

    public static function fromSync(
        Beds24Booking $booking,
        BookingFxSync $sync,
        string        $botSessionId,
    ): self {
        return new self(
            beds24BookingId:     (string) $booking->beds24_booking_id,
            syncId:              $sync->id,
            dailyExchangeRateId: $sync->daily_exchange_rate_id,
            guestName:           $booking->guest_name ?? 'Guest',
            arrivalDate:         $booking->arrival_date->toDateString(),
            uzsPresented:        $sync->uzs_final,
            eurPresented:        $sync->eur_final,
            rubPresented:        $sync->rub_final,
            fxRateDate:          $sync->fx_rate_date->format('d.m.Y'),
            botSessionId:        $botSessionId,
            presentedAt:         now(),
        );
    }

    public function isExpired(): bool
    {
        return $this->presentedAt->diffInMinutes(now()) >= self::TTL_MINUTES;
    }

    /**
     * Returns the pre-computed snapshot amount for the chosen payment currency.
     * This is what the cashier saw on screen — never recalculated.
     */
    public function presentedAmountFor(string $currency): float
    {
        return match (strtoupper($currency)) {
            'UZS'  => (float) $this->uzsPresented,
            'EUR'  => (float) $this->eurPresented,
            'RUB'  => (float) $this->rubPresented,
            default => throw new \InvalidArgumentException(
                "No FX snapshot amount for currency '{$currency}'. " .
                "Only UZS, EUR, RUB are pre-computed."
            ),
        };
    }

    /**
     * Serialize to array for storage in TelegramPosSession->data.
     * presentedAt stored as ISO string to preserve timezone.
     */
    public function toArray(): array
    {
        return [
            'beds24_booking_id'    => $this->beds24BookingId,
            'sync_id'              => $this->syncId,
            'daily_rate_id'        => $this->dailyExchangeRateId,
            'guest_name'           => $this->guestName,
            'arrival_date'         => $this->arrivalDate,
            'uzs_presented'        => $this->uzsPresented,
            'eur_presented'        => $this->eurPresented,
            'rub_presented'        => $this->rubPresented,
            'fx_rate_date'         => $this->fxRateDate,
            'bot_session_id'       => $this->botSessionId,
            'presented_at'         => $this->presentedAt->toIso8601String(),
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            beds24BookingId:     $data['beds24_booking_id'],
            syncId:              $data['sync_id'],
            dailyExchangeRateId: $data['daily_rate_id'],
            guestName:           $data['guest_name'],
            arrivalDate:         $data['arrival_date'],
            uzsPresented:        $data['uzs_presented'],
            eurPresented:        $data['eur_presented'],
            rubPresented:        $data['rub_presented'],
            fxRateDate:          $data['fx_rate_date'],
            botSessionId:        $data['bot_session_id'],
            presentedAt:         Carbon::parse($data['presented_at']),
        );
    }
}
