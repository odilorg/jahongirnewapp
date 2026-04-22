<?php

declare(strict_types=1);

namespace App\Actions\BookingBot;

use App\DTO\BotBookingRequestData;
use App\DTO\ResolvedBotBookingChargeData;
use App\Exceptions\BookingBot\BotBookingChargeResolutionException;
use App\Models\RoomUnitMapping;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Single source of truth for bot-booking charge resolution.
 *
 * Precedence:
 *   1. Operator-supplied (manual) price wins.
 *   2. Otherwise, if auto-compute is enabled, use RoomUnitMapping.base_price.
 *   3. Otherwise no charge (or throw, if require_resolved_charge=true).
 *
 * Currency in auto mode always comes from config default — RoomUnitMapping
 * has no currency column, so we never invent one. See CLAUDE.md memory
 * `project_hotel_management` + LAYER_CHEAT_SHEET for the boundary.
 */
final class ResolveBotBookingChargeAction
{
    public function execute(BotBookingRequestData $data): ResolvedBotBookingChargeData
    {
        $nights = $this->validatedNights($data);

        if (! (bool) config('hotel_booking_bot.pricing.enabled', false)) {
            return ResolvedBotBookingChargeData::none($nights);
        }

        if ($data->inputPricePerNight !== null) {
            return $this->resolveManual($data, $nights);
        }

        if ((bool) config('hotel_booking_bot.pricing.auto_compute_from_room_mapping', false)) {
            $auto = $this->resolveAuto($data, $nights);
            if ($auto !== null) {
                return $auto;
            }
        }

        if ((bool) config('hotel_booking_bot.pricing.require_resolved_charge', false)) {
            throw new BotBookingChargeResolutionException(
                'Charge required but no price provided and no base price on room mapping.'
            );
        }

        return ResolvedBotBookingChargeData::none($nights);
    }

    private function validatedNights(BotBookingRequestData $data): int
    {
        try {
            $arrival   = CarbonImmutable::parse($data->arrival);
            $departure = CarbonImmutable::parse($data->departure);
        } catch (\Throwable $e) {
            throw new BotBookingChargeResolutionException(
                'Invalid stay dates: could not parse arrival or departure.'
            );
        }

        if (! $departure->greaterThan($arrival)) {
            throw new BotBookingChargeResolutionException(
                'Invalid stay dates: departure must be after arrival.'
            );
        }

        $nights = (int) $arrival->diffInDays($departure);
        if ($nights < 1) {
            throw new BotBookingChargeResolutionException(
                'Invalid stay dates: at least one night is required.'
            );
        }

        return $nights;
    }

    private function resolveManual(BotBookingRequestData $data, int $nights): ResolvedBotBookingChargeData
    {
        $price = (float) $data->inputPricePerNight;
        if ($price <= 0) {
            throw new BotBookingChargeResolutionException(
                'Price must be greater than zero.'
            );
        }

        $cap = (float) config('hotel_booking_bot.pricing.max_price_per_night', 10000);
        if ($cap > 0 && $price > $cap) {
            throw new BotBookingChargeResolutionException(
                "Price {$price} per night exceeds the safety cap ({$cap}). Please confirm or adjust."
            );
        }

        $currency = $this->resolveCurrency($data->inputCurrency);

        return new ResolvedBotBookingChargeData(
            hasCharge:     true,
            nights:        $nights,
            pricePerNight: round($price, 2),
            totalAmount:   round($price * $nights, 2),
            currency:      $currency,
            source:        'manual',
            description:   (string) config('hotel_booking_bot.pricing.invoice_item_description', 'Room charge'),
        );
    }

    private function resolveAuto(BotBookingRequestData $data, int $nights): ?ResolvedBotBookingChargeData
    {
        $mapping = RoomUnitMapping::query()
            ->where('property_id', (string) $data->propertyId)
            ->where('room_id', (string) $data->roomId)
            ->first();

        if ($mapping === null) {
            return null;
        }

        $basePrice = (float) ($mapping->base_price ?? 0);
        if ($basePrice <= 0) {
            return null;
        }

        $currency = $this->resolveCurrency(null);

        // Operators will investigate pricing complaints using these logs.
        Log::info('Hotel booking bot: auto price applied', [
            'property_id'      => $data->propertyId,
            'room_id'          => $data->roomId,
            'price_per_night'  => $basePrice,
            'nights'           => $nights,
            'currency'         => $currency,
        ]);

        return new ResolvedBotBookingChargeData(
            hasCharge:     true,
            nights:        $nights,
            pricePerNight: round($basePrice, 2),
            totalAmount:   round($basePrice * $nights, 2),
            currency:      $currency,
            source:        'auto',
            description:   (string) config('hotel_booking_bot.pricing.invoice_item_description', 'Room charge'),
        );
    }

    private function resolveCurrency(?string $input): string
    {
        $currency = $input !== null && $input !== ''
            ? strtoupper($input)
            : strtoupper((string) config('hotel_booking_bot.pricing.default_currency', 'USD'));

        $allowed = (array) config('hotel_booking_bot.pricing.allowed_currencies', ['USD']);
        if (! in_array($currency, $allowed, true)) {
            throw new BotBookingChargeResolutionException(
                "Currency {$currency} is not supported."
            );
        }

        return $currency;
    }
}
