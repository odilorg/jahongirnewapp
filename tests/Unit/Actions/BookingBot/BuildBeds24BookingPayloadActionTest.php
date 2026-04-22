<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\BookingBot;

use App\Actions\BookingBot\BuildBeds24BookingPayloadAction;
use App\DTO\BotBookingRequestData;
use App\DTO\ResolvedBotBookingChargeData;
use PHPUnit\Framework\TestCase;

/**
 * Pure-unit tests for the Beds24 payload translator. No DB, no container.
 */
final class BuildBeds24BookingPayloadActionTest extends TestCase
{
    public function test_builds_payload_without_invoice_items_when_no_charge(): void
    {
        $payload = (new BuildBeds24BookingPayloadAction())->execute(
            $this->data(),
            ResolvedBotBookingChargeData::none(2),
            'Created by test',
        );

        $this->assertArrayNotHasKey('invoiceItems', $payload);
        $this->assertArrayNotHasKey('price', $payload, 'root price must stay out in v1');
    }

    public function test_builds_payload_with_invoice_items_when_charge_present(): void
    {
        $charge = new ResolvedBotBookingChargeData(
            hasCharge:     true,
            nights:        3,
            pricePerNight: 80.0,
            totalAmount:   240.0,
            currency:      'USD',
            source:        'auto',
            description:   'Room charge',
        );

        $payload = (new BuildBeds24BookingPayloadAction())->execute(
            $this->data(),
            $charge,
            'Created by test',
        );

        $this->assertArrayHasKey('invoiceItems', $payload);
        $this->assertCount(1, $payload['invoiceItems']);
        $this->assertSame([
            'type'        => 'charge',
            'description' => 'Room charge',
            'qty'         => 3,
            'amount'      => 80.0,
        ], $payload['invoiceItems'][0]);
    }

    public function test_preserves_core_booking_fields(): void
    {
        $payload = (new BuildBeds24BookingPayloadAction())->execute(
            $this->data(),
            ResolvedBotBookingChargeData::none(2),
            'Created by Op John via Telegram Bot',
        );

        $this->assertSame(41097, $payload['propertyId']);
        $this->assertSame(555, $payload['roomId']);
        $this->assertSame('2026-05-10', $payload['arrival']);
        $this->assertSame('2026-05-12', $payload['departure']);
        $this->assertSame('John', $payload['firstName']);
        $this->assertSame('Walker', $payload['lastName']);
        $this->assertSame('jw@example.com', $payload['email']);
        $this->assertSame('+1234567890', $payload['mobile']);
        $this->assertSame(2, $payload['numAdult']);
        $this->assertSame(0, $payload['numChild']);
        $this->assertSame('confirmed', $payload['status']);
        $this->assertSame('Created by Op John via Telegram Bot', $payload['notes']);
    }

    public function test_null_email_and_mobile_become_empty_strings(): void
    {
        $data = new BotBookingRequestData(
            propertyId:         '41097',
            roomId:             '555',
            arrival:            '2026-05-10',
            departure:          '2026-05-12',
            firstName:          'John',
            lastName:           'Walker',
            email:              null,
            mobile:             null,
            numAdult:           2,
            numChild:           0,
            notes:              null,
            inputPricePerNight: null,
            inputCurrency:      null,
        );

        $payload = (new BuildBeds24BookingPayloadAction())->execute(
            $data,
            ResolvedBotBookingChargeData::none(2),
            'note',
        );

        $this->assertSame('', $payload['email']);
        $this->assertSame('', $payload['mobile']);
    }

    private function data(): BotBookingRequestData
    {
        return new BotBookingRequestData(
            propertyId:         '41097',
            roomId:             '555',
            arrival:            '2026-05-10',
            departure:          '2026-05-12',
            firstName:          'John',
            lastName:           'Walker',
            email:              'jw@example.com',
            mobile:             '+1234567890',
            numAdult:           2,
            numChild:           0,
            notes:              null,
            inputPricePerNight: null,
            inputCurrency:      null,
        );
    }
}
