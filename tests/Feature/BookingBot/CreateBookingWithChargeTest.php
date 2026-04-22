<?php

declare(strict_types=1);

namespace Tests\Feature\BookingBot;

use App\Actions\BookingBot\BuildBeds24BookingPayloadAction;
use App\Actions\BookingBot\Handlers\CreateBookingFromMessageAction;
use App\Actions\BookingBot\ResolveBotBookingChargeAction;
use App\Models\RoomUnitMapping;
use App\Models\User;
use App\Services\Beds24BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * End-to-end wiring test for CreateBookingFromMessageAction with the new
 * charge flow. Beds24BookingService is mocked — we assert on the payload
 * it receives, not live HTTP. Covers:
 *   - manual charge happy path
 *   - auto charge from RoomUnitMapping.base_price
 *   - feature-on-but-unresolved, not required → no charge, booking still created
 *   - required but unresolved → no Beds24 call, operator-facing error
 *   - legacy path with feature disabled → behavior unchanged
 */
final class CreateBookingWithChargeTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = User::factory()->create(['name' => 'Op John']);

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

        config([
            'hotel_booking_bot.pricing.enabled'                        => true,
            'hotel_booking_bot.pricing.auto_compute_from_room_mapping' => true,
            'hotel_booking_bot.pricing.require_resolved_charge'        => false,
            'hotel_booking_bot.pricing.default_currency'               => 'USD',
            'hotel_booking_bot.pricing.allowed_currencies'             => ['USD', 'UZS', 'EUR'],
            'hotel_booking_bot.pricing.max_price_per_night'            => 10000,
            'hotel_booking_bot.pricing.invoice_item_description'       => 'Room charge',
        ]);
    }

    public function test_creates_booking_with_manual_charge_and_confirms_manual_source(): void
    {
        $captured = null;
        $beds24 = $this->mockBeds24Success($captured, bookingId: 'B-1001');

        $reply = $this->action($beds24)->execute(
            $this->parsed(['charge' => ['price_per_night' => 100, 'currency' => 'USD']]),
            $this->staff,
        );

        $this->assertIsArray($captured);
        $this->assertArrayHasKey('invoiceItems', $captured);
        $this->assertSame(
            [['type' => 'charge', 'description' => 'Room charge', 'qty' => 2, 'amount' => 100.0]],
            $captured['invoiceItems'],
        );
        $this->assertStringContainsString('#B-1001', $reply);
        $this->assertStringContainsString('Charge: 100.00 USD/night × 2 nights = 200.00 USD', $reply);
        $this->assertStringContainsString('Source: manual', $reply);
    }

    public function test_creates_booking_with_auto_charge_when_mapping_has_base_price(): void
    {
        $captured = null;
        $beds24 = $this->mockBeds24Success($captured, bookingId: 'B-2002');

        $reply = $this->action($beds24)->execute(
            $this->parsed(), // no charge key
            $this->staff,
        );

        $this->assertArrayHasKey('invoiceItems', $captured);
        $this->assertSame(80.0, $captured['invoiceItems'][0]['amount']);
        $this->assertSame(2, $captured['invoiceItems'][0]['qty']);
        $this->assertStringContainsString('Charge: 80.00 USD/night × 2 nights = 160.00 USD', $reply);
        $this->assertStringContainsString('Source: auto (room base price)', $reply);
    }

    public function test_creates_booking_without_charge_when_feature_on_but_unresolved_and_not_required(): void
    {
        // Remove base_price so auto path cannot resolve.
        RoomUnitMapping::query()->update(['base_price' => 0]);

        $captured = null;
        $beds24 = $this->mockBeds24Success($captured, bookingId: 'B-3003');

        $reply = $this->action($beds24)->execute(
            $this->parsed(), // no charge key
            $this->staff,
        );

        $this->assertArrayNotHasKey('invoiceItems', $captured);
        $this->assertStringContainsString('Charge: not added', $reply);
        $this->assertStringContainsString('#B-3003', $reply);
    }

    public function test_fails_without_calling_beds24_when_charge_required_but_unresolved(): void
    {
        RoomUnitMapping::query()->update(['base_price' => 0]);
        config(['hotel_booking_bot.pricing.require_resolved_charge' => true]);

        $beds24 = Mockery::mock(Beds24BookingService::class);
        $beds24->shouldNotReceive('createBookingFromPayload');
        $beds24->shouldNotReceive('createBooking');

        $reply = $this->action($beds24)->execute($this->parsed(), $this->staff);

        $this->assertStringContainsString('Could not create booking', $reply);
        $this->assertStringContainsString('Charge required', $reply);
    }

    public function test_legacy_path_with_feature_disabled_still_works_without_charge(): void
    {
        config(['hotel_booking_bot.pricing.enabled' => false]);

        $captured = null;
        $beds24 = $this->mockBeds24Success($captured, bookingId: 'B-4004');

        $reply = $this->action($beds24)->execute($this->parsed(), $this->staff);

        $this->assertArrayNotHasKey('invoiceItems', $captured);
        $this->assertStringContainsString('#B-4004', $reply);
        $this->assertStringContainsString('Charge: not added', $reply);
    }

    public function test_rejects_manual_price_over_safety_cap_and_does_not_call_beds24(): void
    {
        config(['hotel_booking_bot.pricing.max_price_per_night' => 1000]);

        $beds24 = Mockery::mock(Beds24BookingService::class);
        $beds24->shouldNotReceive('createBookingFromPayload');

        $reply = $this->action($beds24)->execute(
            $this->parsed(['charge' => ['price_per_night' => 5000, 'currency' => 'USD']]),
            $this->staff,
        );

        $this->assertStringContainsString('Could not create booking', $reply);
        $this->assertStringContainsString('safety cap', $reply);
    }

    /**
     * @param array<string,mixed>|null $captured Passed by reference; filled
     *                                           with the payload Beds24
     *                                           received.
     */
    private function mockBeds24Success(&$captured, string $bookingId): Beds24BookingService&MockInterface
    {
        /** @var Beds24BookingService&MockInterface $mock */
        $mock = Mockery::mock(Beds24BookingService::class);
        $mock->shouldReceive('createBookingFromPayload')
            ->once()
            ->andReturnUsing(function (array $payload) use (&$captured, $bookingId): array {
                $captured = $payload;
                return [
                    'success'   => true,
                    'bookingId' => $bookingId,
                    'id'        => $bookingId,
                    'data'      => ['success' => true, 'new' => ['id' => $bookingId]],
                ];
            });

        return $mock;
    }

    private function action(Beds24BookingService $beds24): CreateBookingFromMessageAction
    {
        return new CreateBookingFromMessageAction(
            $beds24,
            new ResolveBotBookingChargeAction(),
            new BuildBeds24BookingPayloadAction(),
        );
    }

    /** @param array<string,mixed> $overrides */
    private function parsed(array $overrides = []): array
    {
        return array_merge([
            'intent' => 'create_booking',
            'room'   => ['unit_name' => '12'],
            'guest'  => [
                'name'  => 'John Walker',
                'phone' => '+1234567890',
                'email' => 'jw@example.com',
            ],
            'dates' => [
                'check_in'  => '2026-05-10',
                'check_out' => '2026-05-12',
            ],
        ], $overrides);
    }
}
