<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\BookingBot;

use App\Actions\BookingBot\ResolveBotBookingChargeAction;
use App\DTO\BotBookingRequestData;
use App\DTO\ResolvedBotBookingChargeData;
use App\Exceptions\BookingBot\BotBookingChargeResolutionException;
use App\Models\RoomUnitMapping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the single source of truth for bot-booking charge resolution:
 * flag gating, manual/auto precedence, currency normalization, date
 * validation, and the require_resolved_charge hard-fail path.
 */
final class ResolveBotBookingChargeActionTest extends TestCase
{
    use RefreshDatabase;

    private ResolveBotBookingChargeAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = new ResolveBotBookingChargeAction();

        config([
            'hotel_booking_bot.pricing.enabled'                        => true,
            'hotel_booking_bot.pricing.auto_compute_from_room_mapping' => false,
            'hotel_booking_bot.pricing.require_resolved_charge'        => false,
            'hotel_booking_bot.pricing.default_currency'               => 'USD',
            'hotel_booking_bot.pricing.allowed_currencies'             => ['USD', 'UZS', 'EUR'],
            'hotel_booking_bot.pricing.max_price_per_night'            => 10000,
            'hotel_booking_bot.pricing.invoice_item_description'       => 'Room charge',
        ]);
    }

    public function test_returns_no_charge_when_pricing_feature_disabled(): void
    {
        config(['hotel_booking_bot.pricing.enabled' => false]);

        $result = $this->action->execute($this->data(inputPricePerNight: 100.0));

        $this->assertFalse($result->hasCharge);
        $this->assertNull($result->pricePerNight);
        $this->assertNull($result->totalAmount);
        $this->assertNull($result->currency);
        $this->assertNull($result->source);
        $this->assertSame(2, $result->nights);
    }

    public function test_resolves_manual_price_with_explicit_currency(): void
    {
        $result = $this->action->execute($this->data(
            inputPricePerNight: 100.0,
            inputCurrency:      'USD',
        ));

        $this->assertTrue($result->hasCharge);
        $this->assertSame('manual', $result->source);
        $this->assertSame(100.0, $result->pricePerNight);
        $this->assertSame(200.0, $result->totalAmount);
        $this->assertSame('USD', $result->currency);
        $this->assertSame('Room charge', $result->description);
    }

    public function test_resolves_manual_price_with_default_currency_when_omitted(): void
    {
        config(['hotel_booking_bot.pricing.default_currency' => 'EUR']);

        $result = $this->action->execute($this->data(
            inputPricePerNight: 80.0,
            inputCurrency:      null,
        ));

        $this->assertSame('EUR', $result->currency);
        $this->assertSame('manual', $result->source);
    }

    public function test_currency_is_normalized_to_uppercase(): void
    {
        $result = $this->action->execute($this->data(
            inputPricePerNight: 50.0,
            inputCurrency:      'usd',
        ));

        $this->assertSame('USD', $result->currency);
    }

    public function test_rejects_unsupported_currency(): void
    {
        $this->expectException(BotBookingChargeResolutionException::class);
        $this->expectExceptionMessage('Currency GBP is not supported.');

        $this->action->execute($this->data(
            inputPricePerNight: 100.0,
            inputCurrency:      'GBP',
        ));
    }

    public function test_rejects_zero_manual_price(): void
    {
        $this->expectException(BotBookingChargeResolutionException::class);
        $this->expectExceptionMessage('Price must be greater than zero.');

        $this->action->execute($this->data(inputPricePerNight: 0.0));
    }

    public function test_rejects_negative_manual_price(): void
    {
        $this->expectException(BotBookingChargeResolutionException::class);

        $this->action->execute($this->data(inputPricePerNight: -5.0));
    }

    public function test_rejects_manual_price_above_safety_cap(): void
    {
        config(['hotel_booking_bot.pricing.max_price_per_night' => 1000]);

        $this->expectException(BotBookingChargeResolutionException::class);
        $this->expectExceptionMessageMatches('/exceeds the safety cap/');

        $this->action->execute($this->data(inputPricePerNight: 5000.0));
    }

    public function test_resolves_auto_price_from_room_unit_mapping(): void
    {
        config(['hotel_booking_bot.pricing.auto_compute_from_room_mapping' => true]);

        RoomUnitMapping::create([
            'unit_name'     => '12',
            'property_id'   => '41097',
            'property_name' => 'Jahongir Hotel',
            'room_id'       => '555',
            'room_name'     => 'Double Standard',
            'room_type'     => 'double',
            'max_guests'    => 2,
            'base_price'    => 80.0,
        ]);

        $result = $this->action->execute($this->data(
            inputPricePerNight: null,
            propertyId:         '41097',
            roomId:             '555',
            arrival:            '2026-05-10',
            departure:          '2026-05-13', // 3 nights
        ));

        $this->assertTrue($result->hasCharge);
        $this->assertSame('auto', $result->source);
        $this->assertSame(80.0, $result->pricePerNight);
        $this->assertSame(240.0, $result->totalAmount);
        $this->assertSame(3, $result->nights);
    }

    public function test_manual_price_overrides_auto_mapping(): void
    {
        config(['hotel_booking_bot.pricing.auto_compute_from_room_mapping' => true]);

        RoomUnitMapping::create([
            'unit_name'     => '12',
            'property_id'   => '41097',
            'property_name' => 'Jahongir Hotel',
            'room_id'       => '555',
            'room_name'     => 'Double Standard',
            'room_type'     => 'double',
            'max_guests'    => 2,
            'base_price'    => 80.0,
        ]);

        $result = $this->action->execute($this->data(
            inputPricePerNight: 100.0,
            propertyId:         '41097',
            roomId:             '555',
        ));

        $this->assertSame('manual', $result->source);
        $this->assertSame(100.0, $result->pricePerNight);
    }

    public function test_unresolved_returns_no_charge_when_not_required(): void
    {
        config([
            'hotel_booking_bot.pricing.auto_compute_from_room_mapping' => true,
            'hotel_booking_bot.pricing.require_resolved_charge'        => false,
        ]);

        // No mapping row, no manual price.
        $result = $this->action->execute($this->data(inputPricePerNight: null));

        $this->assertFalse($result->hasCharge);
        $this->assertNull($result->source);
    }

    public function test_unresolved_throws_when_required(): void
    {
        config([
            'hotel_booking_bot.pricing.auto_compute_from_room_mapping' => false,
            'hotel_booking_bot.pricing.require_resolved_charge'        => true,
        ]);

        $this->expectException(BotBookingChargeResolutionException::class);
        $this->expectExceptionMessageMatches('/Charge required/');

        $this->action->execute($this->data(inputPricePerNight: null));
    }

    public function test_auto_with_base_price_zero_is_treated_as_unresolved(): void
    {
        config(['hotel_booking_bot.pricing.auto_compute_from_room_mapping' => true]);

        RoomUnitMapping::create([
            'unit_name'     => '12',
            'property_id'   => '41097',
            'property_name' => 'Jahongir Hotel',
            'room_id'       => '555',
            'room_name'     => 'Double Standard',
            'room_type'     => 'double',
            'max_guests'    => 2,
            'base_price'    => 0.0,
        ]);

        $result = $this->action->execute($this->data(
            inputPricePerNight: null,
            propertyId:         '41097',
            roomId:             '555',
        ));

        $this->assertFalse($result->hasCharge);
    }

    public function test_invalid_date_range_throws(): void
    {
        $this->expectException(BotBookingChargeResolutionException::class);
        $this->expectExceptionMessage('departure must be after arrival');

        $this->action->execute($this->data(
            arrival:   '2026-05-10',
            departure: '2026-05-10',
        ));
    }

    public function test_departure_before_arrival_throws(): void
    {
        $this->expectException(BotBookingChargeResolutionException::class);

        $this->action->execute($this->data(
            arrival:   '2026-05-10',
            departure: '2026-05-09',
        ));
    }

    private function data(
        int|string $propertyId        = '41097',
        int|string $roomId            = '555',
        string     $arrival           = '2026-05-10',
        string     $departure         = '2026-05-12',
        ?float     $inputPricePerNight = null,
        ?string    $inputCurrency      = null,
    ): BotBookingRequestData {
        return new BotBookingRequestData(
            propertyId:         $propertyId,
            roomId:             $roomId,
            arrival:            $arrival,
            departure:          $departure,
            firstName:          'John',
            lastName:           'Walker',
            email:              'jw@example.com',
            mobile:             '+1234567890',
            numAdult:           2,
            numChild:           0,
            notes:              null,
            inputPricePerNight: $inputPricePerNight,
            inputCurrency:      $inputCurrency,
        );
    }
}
