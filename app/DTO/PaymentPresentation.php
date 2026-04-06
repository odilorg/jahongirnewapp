<?php

namespace App\DTO;

use App\DTO\GroupAmountResolution;
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
        public string  $beds24BookingId,
        public int     $syncId,
        public ?int    $dailyExchangeRateId,
        public string  $guestName,
        public string  $arrivalDate,
        public int     $uzsPresented,
        public int     $eurPresented,
        public int     $rubPresented,
        public string  $fxRateDate,         // formatted: "28.03.2026"
        public string  $botSessionId,
        public Carbon  $presentedAt,

        // Group booking context — null/false for standalone bookings
        public bool    $isGroupPayment          = false,
        public ?string $groupMasterBookingId    = null,
        public ?int    $groupSizeExpected       = null,
        public ?int    $groupSizeLocal          = null,
    ) {}

    public static function fromSync(
        Beds24Booking         $booking,
        BookingFxSync         $sync,
        string                $botSessionId,
        ?GroupAmountResolution $resolution = null,
    ): self {
        return new self(
            beds24BookingId:         (string) $booking->beds24_booking_id,
            syncId:                  $sync->id,
            dailyExchangeRateId:     $sync->daily_exchange_rate_id,
            guestName:               $booking->guest_name ?? 'Guest',
            arrivalDate:             $booking->arrival_date->toDateString(),
            uzsPresented:            $sync->uzs_final,
            eurPresented:            $sync->eur_final,
            rubPresented:            $sync->rub_final,
            fxRateDate:              $sync->fx_rate_date->format('d.m.Y'),
            botSessionId:            $botSessionId,
            presentedAt:             now(),
            // Group context — populated when resolver was used, defaults for non-cashier callers
            isGroupPayment:          $resolution !== null && ! $resolution->isSingleBooking,
            groupMasterBookingId:    $resolution?->effectiveMasterBookingId,
            groupSizeExpected:       $resolution?->groupSizeExpected,
            groupSizeLocal:          $resolution?->groupSizeLocal,
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
            'beds24_booking_id'       => $this->beds24BookingId,
            'sync_id'                 => $this->syncId,
            'daily_rate_id'           => $this->dailyExchangeRateId,
            'guest_name'              => $this->guestName,
            'arrival_date'            => $this->arrivalDate,
            'uzs_presented'           => $this->uzsPresented,
            'eur_presented'           => $this->eurPresented,
            'rub_presented'           => $this->rubPresented,
            'fx_rate_date'            => $this->fxRateDate,
            'bot_session_id'          => $this->botSessionId,
            'presented_at'            => $this->presentedAt->toIso8601String(),
            // Group fields — always serialized; defaults keep old sessions valid
            'is_group_payment'        => $this->isGroupPayment,
            'group_master_booking_id' => $this->groupMasterBookingId,
            'group_size_expected'     => $this->groupSizeExpected,
            'group_size_local'        => $this->groupSizeLocal,
        ];
    }

    public static function fromArray(array $data): self
    {
        // Validate required keys — missing keys mean the session was corrupted mid-write.
        // Throw explicitly rather than letting a typed constructor receive null and crash with a
        // cryptic TypeError. Caller (CashierBotController) catches \Throwable and shows the
        // "FX unavailable" message, which is the correct UX for this scenario.
        $required = [
            'beds24_booking_id', 'sync_id', 'guest_name', 'arrival_date',
            'uzs_presented', 'eur_presented', 'rub_presented',
            'fx_rate_date', 'bot_session_id', 'presented_at',
            // Group fields are NOT required — sessions written before this feature
            // will not have them; defaults (false/null) are applied below.
        ];

        foreach ($required as $key) {
            if (! array_key_exists($key, $data)) {
                throw new \InvalidArgumentException(
                    "PaymentPresentation::fromArray() missing required key: '{$key}'. "
                    . "Session data may be corrupted."
                );
            }
        }

        return new self(
            beds24BookingId:         $data['beds24_booking_id'],
            syncId:                  $data['sync_id'],
            dailyExchangeRateId:     $data['daily_rate_id'] ?? null,
            guestName:               $data['guest_name'],
            arrivalDate:             $data['arrival_date'],
            uzsPresented:            $data['uzs_presented'],
            eurPresented:            $data['eur_presented'],
            rubPresented:            $data['rub_presented'],
            fxRateDate:              $data['fx_rate_date'],
            botSessionId:            $data['bot_session_id'],
            presentedAt:             Carbon::parse($data['presented_at']),
            // Group fields — optional; default to standalone for old sessions
            isGroupPayment:          (bool)  ($data['is_group_payment']        ?? false),
            groupMasterBookingId:    isset($data['group_master_booking_id']) && $data['group_master_booking_id'] !== ''
                                         ? (string) $data['group_master_booking_id']
                                         : null,
            groupSizeExpected:       isset($data['group_size_expected']) ? (int) $data['group_size_expected'] : null,
            groupSizeLocal:          isset($data['group_size_local'])    ? (int) $data['group_size_local']    : null,
        );
    }
}
