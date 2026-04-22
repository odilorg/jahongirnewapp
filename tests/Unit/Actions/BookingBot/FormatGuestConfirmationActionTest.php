<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\BookingBot;

use App\Actions\BookingBot\FormatGuestConfirmationAction;
use App\DTO\BotBookingRequestData;
use App\DTO\ResolvedBotBookingChargeData;
use App\Models\RoomUnitMapping;
use Tests\TestCase;

/**
 * Unit tests for the bilingual guest-forward formatter. No DB — the
 * formatter only reads Eloquent model attributes off an in-memory
 * instance, never queries.
 */
final class FormatGuestConfirmationActionTest extends TestCase
{
    private FormatGuestConfirmationAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = new FormatGuestConfirmationAction();

        config([
            'hotel_booking_bot.guest_confirmation.enabled'                 => true,
            'hotel_booking_bot.guest_confirmation.defaults.phone'          => '+998 94 880 11 99',
            'hotel_booking_bot.guest_confirmation.defaults.whatsapp'       => '+998 94 880 11 99',
            'hotel_booking_bot.guest_confirmation.defaults.check_in_time'  => '14:00',
            'hotel_booking_bot.guest_confirmation.defaults.check_out_time' => '12:00',
            'hotel_booking_bot.guest_confirmation.properties.41097'        => [
                'name_en'   => 'Jahongir Hotel',
                'name_ru'   => 'Отель Jahongir',
                'address'   => '9 Kamil Khoramiy, Samarkand',
                'maps_link' => 'https://maps.google.com/?q=Jahongir+Hotel',
            ],
            'hotel_booking_bot.guest_confirmation.properties.172793'       => [
                'name_en'   => 'Jahongir Premium',
                'name_ru'   => 'Jahongir Premium',
                'address'   => '42 Registan St, Samarkand',
                'maps_link' => 'https://maps.google.com/?q=Jahongir+Premium',
            ],
        ]);
    }

    public function test_returns_empty_when_feature_disabled(): void
    {
        config(['hotel_booking_bot.guest_confirmation.enabled' => false]);

        $out = $this->action->execute(
            $this->data(),
            $this->chargeManual(),
            [$this->room(propertyId: '41097')],
            [1001],
        );

        $this->assertSame('', $out);
    }

    public function test_returns_empty_when_no_property_config(): void
    {
        $out = $this->action->execute(
            $this->data(),
            $this->chargeManual(),
            [$this->room(propertyId: '999999')], // unknown property
            [1001],
        );

        $this->assertSame('', $out);
    }

    public function test_single_room_with_manual_charge_bilingual(): void
    {
        $out = $this->action->execute(
            $this->data(),
            $this->chargeManual(nights: 2, price: 80.0, total: 160.0),
            [$this->room()],
            [1001],
        );

        $this->assertStringContainsString('Booking confirmation / Подтверждение брони', $out);
        $this->assertStringContainsString('Hello, John!', $out);
        $this->assertStringContainsString('Здравствуйте, John!', $out);
        $this->assertStringContainsString('Your reservation at Jahongir Hotel is confirmed.', $out);
        $this->assertStringContainsString('Ваше бронирование в Отель Jahongir подтверждено.', $out);
        $this->assertStringContainsString('Hotel / Отель: Jahongir Hotel', $out);
        $this->assertStringContainsString('Rooms / Номера: 12 — Double Room', $out);
        $this->assertStringContainsString('Reference / Номер брони: #1001', $out);
        $this->assertStringContainsString('Price / Стоимость: 80.00 USD per night × 2 nights = 160.00 USD', $out);
        $this->assertStringContainsString('Check-in / Заезд: from 14:00', $out);
        $this->assertStringContainsString('Check-out / Выезд: until 12:00', $out);
        $this->assertStringContainsString('Address / Адрес: 9 Kamil Khoramiy, Samarkand', $out);
        $this->assertStringContainsString('Map / Карта: https://maps.google.com/?q=Jahongir+Hotel', $out);
        $this->assertStringContainsString('Phone: +998 94 880 11 99', $out);
        $this->assertStringContainsString('WhatsApp: +998 94 880 11 99', $out);
        $this->assertStringContainsString('See you soon!', $out);
        $this->assertStringContainsString('— Jahongir Hotel', $out);
    }

    public function test_single_room_one_night_uses_singular_night_word(): void
    {
        $out = $this->action->execute(
            $this->data(arrival: '2026-05-10', departure: '2026-05-11'),
            $this->chargeManual(nights: 1, price: 50.0, total: 50.0),
            [$this->room()],
            [1001],
        );

        $this->assertStringContainsString('(1 night)', $out);
        $this->assertStringContainsString('× 1 night = 50.00 USD', $out);
        $this->assertStringNotContainsString('1 nights', $out);
    }

    public function test_no_charge_omits_price_block_entirely(): void
    {
        $out = $this->action->execute(
            $this->data(),
            ResolvedBotBookingChargeData::none(2),
            [$this->room()],
            [1001],
        );

        $this->assertStringNotContainsString('Price', $out);
        $this->assertStringNotContainsString('Стоимость', $out);
        $this->assertStringNotContainsString('not added', $out);
    }

    public function test_group_two_rooms_premium_shows_per_room_and_group_total(): void
    {
        $out = $this->action->execute(
            $this->data(),
            $this->chargeManual(nights: 2, price: 80.0, total: 160.0),
            [
                $this->room(unit: '11', propertyId: '172793', roomName: 'Standard Double'),
                $this->room(unit: '12', propertyId: '172793', roomName: 'Double or Twin'),
            ],
            [2001, 2002],
        );

        $this->assertStringContainsString('Hotel / Отель: Jahongir Premium', $out);
        $this->assertStringContainsString('Ваше бронирование в Jahongir Premium подтверждено.', $out);
        $this->assertStringContainsString('Rooms / Номера: 11 — Standard Double, 12 — Double or Twin', $out);
        $this->assertStringContainsString('Reference / Номер брони: #2001 / #2002', $out);
        $this->assertStringContainsString('Price / Стоимость: 80.00 USD per room per night × 2 nights', $out);
        $this->assertStringContainsString('Group total / Общая сумма: 320.00 USD', $out);
    }

    public function test_group_without_charge(): void
    {
        $out = $this->action->execute(
            $this->data(),
            ResolvedBotBookingChargeData::none(2),
            [
                $this->room(unit: '11'),
                $this->room(unit: '14', roomName: 'Double B'),
            ],
            [3001, 3002],
        );

        $this->assertStringContainsString('Reference / Номер брони: #3001 / #3002', $out);
        $this->assertStringNotContainsString('Price', $out);
        $this->assertStringNotContainsString('Group total', $out);
    }

    public function test_missing_address_and_maps_are_omitted_cleanly(): void
    {
        config([
            'hotel_booking_bot.guest_confirmation.properties.41097.address'   => '',
            'hotel_booking_bot.guest_confirmation.properties.41097.maps_link' => '',
        ]);

        $out = $this->action->execute(
            $this->data(),
            ResolvedBotBookingChargeData::none(2),
            [$this->room()],
            [1001],
        );

        $this->assertStringNotContainsString('Address', $out);
        $this->assertStringNotContainsString('Map', $out);
        // Phone/WA still present.
        $this->assertStringContainsString('Phone:', $out);
    }

    private function data(
        string $firstName = 'John',
        string $arrival = '2026-07-15',
        string $departure = '2026-07-17',
    ): BotBookingRequestData {
        return new BotBookingRequestData(
            propertyId:         '41097',
            roomId:             '555',
            arrival:            $arrival,
            departure:          $departure,
            firstName:          $firstName,
            lastName:           'Walker',
            email:              null,
            mobile:             null,
            numAdult:           2,
            numChild:           0,
            notes:              null,
            inputPricePerNight: null,
            inputCurrency:      null,
        );
    }

    private function chargeManual(int $nights = 2, float $price = 80.0, float $total = 160.0): ResolvedBotBookingChargeData
    {
        return new ResolvedBotBookingChargeData(
            hasCharge:     true,
            nights:        $nights,
            pricePerNight: $price,
            totalAmount:   $total,
            currency:      'USD',
            source:        'manual',
            description:   'Room charge',
        );
    }

    private function room(string $unit = '12', string $propertyId = '41097', string $roomName = 'Double Room'): RoomUnitMapping
    {
        // In-memory model — never persisted. The formatter only reads
        // property_id / unit_name / room_name off this object, so we
        // avoid a DB round-trip + unique-constraint collisions.
        $m = new RoomUnitMapping();
        $m->unit_name     = $unit;
        $m->property_id   = $propertyId;
        $m->property_name = $propertyId === '41097' ? 'Jahongir Hotel' : 'Jahongir Premium';
        $m->room_id       = '999';
        $m->room_name     = $roomName;
        $m->room_type     = 'double';
        $m->max_guests    = 2;
        $m->base_price    = null;
        return $m;
    }
}
