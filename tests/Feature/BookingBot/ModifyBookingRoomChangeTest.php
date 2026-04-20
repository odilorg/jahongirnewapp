<?php

declare(strict_types=1);

namespace Tests\Feature\BookingBot;

use App\Actions\BookingBot\Handlers\ModifyBookingFromMessageAction;
use App\Models\RoomUnitMapping;
use App\Models\User;
use App\Services\Beds24BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Room-only modification regression test — lives in its own class because
 * it needs a real RoomUnitMapping row and therefore RefreshDatabase, whereas
 * the rest of ModifyBookingFromMessageActionTest runs on pure mocks.
 *
 * Keeping this separate means the mock-only tests stay runnable on dev
 * machines without a MySQL driver; this class only runs on VPS/CI.
 */
final class ModifyBookingRoomChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_room_only_modification_does_not_throw_and_skips_availability_check(): void
    {
        // The room change is resolved by looking up a RoomUnitMapping row by
        // unit_name + property hint — so a real DB row is required.
        RoomUnitMapping::create([
            'unit_name'     => '14',
            'property_id'   => '172793',
            'property_name' => 'Jahongir Premium',
            'room_id'       => '377292',
            'room_name'     => 'Deluxe Double',
            'room_type'     => 'deluxe',
            'max_guests'    => 2,
            'base_price'    => 80.00,
        ]);

        /** @var Beds24BookingService&\Mockery\MockInterface $beds24 */
        $beds24 = Mockery::mock(Beds24BookingService::class);

        $beds24->shouldReceive('getBooking')
            ->once()
            ->with('85624740')
            ->andReturn(['data' => [[
                'arrival'    => '2026-12-01',
                'departure'  => '2026-12-02',
                'firstName'  => 'Keep',
                'lastName'   => 'Me',
                'roomName'   => 'Double or Twin',
                'roomId'     => 377291,
                'propertyId' => 172793,
            ]]]);

        // Room-only change: availability guard MUST NOT call checkAvailability
        // because no date is moving. This was the second undefined-variable
        // crash path alongside guest-only.
        $beds24->shouldNotReceive('checkAvailability');

        $beds24->shouldReceive('modifyBooking')
            ->once()
            ->with('85624740', Mockery::on(function (array $changes) {
                return $changes === ['roomId' => 377292];
            }))
            ->andReturn([['success' => true]]);

        $action = new ModifyBookingFromMessageAction($beds24);
        $staff = new User(['name' => 'Test Staff']);

        $reply = $action->execute([
            'booking_id' => '85624740',
            'room'       => ['unit_name' => '14'],
            'property'   => 'premium',
        ], $staff);

        $this->assertStringContainsString('Booking Modified Successfully', $reply);
        $this->assertStringContainsString('Room: Double or Twin → Unit 14 (Deluxe Double)', $reply);
    }
}
