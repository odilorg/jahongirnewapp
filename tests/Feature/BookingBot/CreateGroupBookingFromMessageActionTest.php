<?php

declare(strict_types=1);

namespace Tests\Feature\BookingBot;

use App\Actions\BookingBot\BuildBeds24BookingPayloadAction;
use App\Actions\BookingBot\Handlers\CreateGroupBookingFromMessageAction;
use App\Actions\BookingBot\ResolveBotBookingChargeAction;
use App\Models\RoomUnitMapping;
use App\Models\User;
use App\Services\Beds24BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * End-to-end wiring test for the group-booking Action.
 *
 * Covers all six Phase 7 locked rules:
 *   1. per-room per-night pricing
 *   2. single guest for whole group
 *   3. atomic create (any failure → rollback)
 *   4. master = first payload element
 *   5. duplicate room rejection
 *   6. same-property requirement
 */
final class CreateGroupBookingFromMessageActionTest extends TestCase
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
            'room_name'     => 'Double A',
            'room_type'     => 'double',
            'max_guests'    => 2,
            'base_price'    => null,
        ]);
        RoomUnitMapping::create([
            'unit_name'     => '14',
            'property_id'   => '41097',
            'property_name' => 'Jahongir Hotel',
            'room_id'       => '556',
            'room_name'     => 'Double B',
            'room_type'     => 'double',
            'max_guests'    => 2,
            'base_price'    => null,
        ]);
        RoomUnitMapping::create([
            'unit_name'     => '21',
            'property_id'   => '172793',
            'property_name' => 'Jahongir Premium',
            'room_id'       => '777',
            'room_name'     => 'Suite',
            'room_type'     => 'suite',
            'max_guests'    => 2,
            'base_price'    => null,
        ]);

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

    public function test_creates_group_with_manual_charge_applied_per_room(): void
    {
        $captured = null;
        $beds24 = $this->mockBeds24GroupSuccess($captured, masterId: 5001, siblingIds: [5002]);

        $reply = $this->action($beds24)->execute(
            $this->parsed(rooms: [['unit_name' => '12'], ['unit_name' => '14']], charge: ['price_per_night' => 80, 'currency' => 'USD']),
            $this->staff,
        );

        $this->assertIsArray($captured);
        $this->assertCount(2, $captured);
        $this->assertSame(
            [['type' => 'charge', 'description' => 'Room charge', 'qty' => 2, 'amount' => 80.0]],
            $captured[0]['invoiceItems'],
        );
        $this->assertSame(
            [['type' => 'charge', 'description' => 'Room charge', 'qty' => 2, 'amount' => 80.0]],
            $captured[1]['invoiceItems'],
        );
        $this->assertStringContainsString('Group Booking Created Successfully!', $reply);
        $this->assertStringContainsString('Master: #5001', $reply);
        $this->assertStringContainsString('#5001, #5002', $reply);
        $this->assertStringContainsString('Per room: 160.00 USD   Group total: 320.00 USD', $reply);
        $this->assertStringContainsString('Source: manual', $reply);
    }

    public function test_master_is_first_payload_element_regardless_of_parser_order(): void
    {
        $captured = null;
        $beds24 = $this->mockBeds24GroupSuccess($captured, masterId: 9001, siblingIds: [9002]);

        $reply = $this->action($beds24)->execute(
            $this->parsed(rooms: [['unit_name' => '14'], ['unit_name' => '12']]),
            $this->staff,
        );

        // Rule 4: master = first in the payload we sent (which is first in
        // the resolved rooms list, which preserves parser input order).
        $this->assertSame('556', (string) $captured[0]['roomId']); // unit 14 first
        $this->assertSame('555', (string) $captured[1]['roomId']); // unit 12 second
        $this->assertStringContainsString('Master: #9001', $reply);
    }

    public function test_creates_group_without_invoice_items_when_no_price_given(): void
    {
        $captured = null;
        $beds24 = $this->mockBeds24GroupSuccess($captured, masterId: 6001, siblingIds: [6002]);

        $reply = $this->action($beds24)->execute(
            $this->parsed(rooms: [['unit_name' => '12'], ['unit_name' => '14']]),
            $this->staff,
        );

        $this->assertArrayNotHasKey('invoiceItems', $captured[0]);
        $this->assertArrayNotHasKey('invoiceItems', $captured[1]);
        $this->assertStringContainsString('Charge: not added', $reply);
    }

    public function test_rejects_duplicate_rooms_without_calling_beds24(): void
    {
        $beds24 = Mockery::mock(Beds24BookingService::class);
        $beds24->shouldNotReceive('createMultipleBookingsFromPayloads');

        $reply = $this->action($beds24)->execute(
            $this->parsed(rooms: [['unit_name' => '12'], ['unit_name' => '12']]),
            $this->staff,
        );

        $this->assertStringContainsString('Duplicate rooms detected', $reply);
        $this->assertStringContainsString('12', $reply);
    }

    public function test_rejects_mixed_property_groups_without_calling_beds24(): void
    {
        $beds24 = Mockery::mock(Beds24BookingService::class);
        $beds24->shouldNotReceive('createMultipleBookingsFromPayloads');

        // Hint each room to its own property.
        $reply = $this->action($beds24)->execute(
            $this->parsed(rooms: [
                ['unit_name' => '12', 'property' => 'jahongir_hotel'],
                ['unit_name' => '21', 'property' => 'jahongir_premium'],
            ]),
            $this->staff,
        );

        $this->assertStringContainsString('same property', $reply);
    }

    public function test_rejects_unresolvable_room_without_calling_beds24(): void
    {
        $beds24 = Mockery::mock(Beds24BookingService::class);
        $beds24->shouldNotReceive('createMultipleBookingsFromPayloads');

        $reply = $this->action($beds24)->execute(
            $this->parsed(rooms: [['unit_name' => '12'], ['unit_name' => '999']]),
            $this->staff,
        );

        $this->assertStringContainsString('Room 999 not found', $reply);
    }

    public function test_rolls_back_created_siblings_on_partial_failure(): void
    {
        $beds24 = Mockery::mock(Beds24BookingService::class);
        // First room created, second rejected.
        $beds24->shouldReceive('createMultipleBookingsFromPayloads')
            ->once()
            ->andReturn([
                ['success' => true,  'new' => ['id' => 7001]],
                ['success' => false, 'errors' => [['field' => 'arrival', 'message' => 'invalid']]],
            ]);
        // The created sibling MUST be cancelled (Rule 3).
        $beds24->shouldReceive('cancelBooking')
            ->once()
            ->with('7001', Mockery::any())
            ->andReturn([['success' => true, 'modified' => ['status' => 'cancelled']]]);

        $reply = $this->action($beds24)->execute(
            $this->parsed(rooms: [['unit_name' => '12'], ['unit_name' => '14']]),
            $this->staff,
        );

        $this->assertStringContainsString('Group booking failed', $reply);
        $this->assertStringContainsString('All rooms released', $reply);
    }

    public function test_rejects_manual_price_over_safety_cap_without_calling_beds24(): void
    {
        config(['hotel_booking_bot.pricing.max_price_per_night' => 1000]);

        $beds24 = Mockery::mock(Beds24BookingService::class);
        $beds24->shouldNotReceive('createMultipleBookingsFromPayloads');

        $reply = $this->action($beds24)->execute(
            $this->parsed(
                rooms: [['unit_name' => '12'], ['unit_name' => '14']],
                charge: ['price_per_night' => 5000, 'currency' => 'USD'],
            ),
            $this->staff,
        );

        $this->assertStringContainsString('Could not create group booking', $reply);
        $this->assertStringContainsString('safety cap', $reply);
    }

    public function test_rejects_single_room_input_to_group_action(): void
    {
        $beds24 = Mockery::mock(Beds24BookingService::class);
        $beds24->shouldNotReceive('createMultipleBookingsFromPayloads');

        $reply = $this->action($beds24)->execute(
            $this->parsed(rooms: [['unit_name' => '12']]),
            $this->staff,
        );

        $this->assertStringContainsString('at least two rooms', $reply);
    }

    private function action(Beds24BookingService $beds24): CreateGroupBookingFromMessageAction
    {
        return new CreateGroupBookingFromMessageAction(
            $beds24,
            new ResolveBotBookingChargeAction(),
            new BuildBeds24BookingPayloadAction(),
        );
    }

    /**
     * @param array<string,mixed>|null $captured Filled with the payload array sent to Beds24.
     * @param list<int>                $siblingIds
     */
    private function mockBeds24GroupSuccess(&$captured, int $masterId, array $siblingIds): Beds24BookingService&MockInterface
    {
        /** @var Beds24BookingService&MockInterface $mock */
        $mock = Mockery::mock(Beds24BookingService::class);
        $mock->shouldReceive('createMultipleBookingsFromPayloads')
            ->once()
            ->andReturnUsing(function (array $payloads, bool $makeGroup) use (&$captured, $masterId, $siblingIds): array {
                $captured = $payloads;
                $out = [
                    ['success' => true, 'new' => ['id' => $masterId], 'info' => [['action' => 'new booking', 'id' => $masterId]]],
                ];
                foreach ($siblingIds as $sid) {
                    $out[] = ['success' => true, 'new' => ['id' => $sid, 'masterId' => $masterId], 'info' => [['action' => 'new booking', 'id' => $sid]]];
                }
                return $out;
            });
        return $mock;
    }

    /** @param list<array<string, string>> $rooms @param array<string, mixed> $charge */
    private function parsed(array $rooms, array $charge = []): array
    {
        $out = [
            'intent' => 'create_booking',
            'rooms'  => $rooms,
            'guest'  => [
                'name'  => 'John Walker',
                'phone' => '+998000000001',
                'email' => 'jw@example.com',
            ],
            'dates' => [
                'check_in'  => '2026-08-05',
                'check_out' => '2026-08-07',
            ],
        ];
        if ($charge !== []) {
            $out['charge'] = $charge;
        }
        return $out;
    }
}
