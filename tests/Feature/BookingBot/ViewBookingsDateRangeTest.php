<?php

declare(strict_types=1);

namespace Tests\Feature\BookingBot;

use App\Actions\BookingBot\Handlers\ViewBookingsFromMessageAction;
use App\Models\RoomUnitMapping;
use App\Services\Beds24BookingService;
use App\Services\BookingBot\BookingListFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * End-to-end wiring of Phase 9 rules. Beds24 is mocked; we assert on
 * the exact filter shape it receives.
 */
final class ViewBookingsDateRangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RoomUnitMapping::create([
            'unit_name'     => '12',
            'property_id'   => '41097',
            'property_name' => 'Jahongir Hotel',
            'room_id'       => '555',
            'room_name'     => 'Double A',
            'room_type'     => 'double',
            'max_guests'    => 2,
        ]);
        RoomUnitMapping::create([
            'unit_name'     => '21',
            'property_id'   => '172793',
            'property_name' => 'Jahongir Premium',
            'room_id'       => '777',
            'room_name'     => 'Suite',
            'room_type'     => 'suite',
            'max_guests'    => 2,
        ]);

        config([
            'hotel_booking_bot.view.max_range_days' => 31,
            'hotel_booking_bot.view.max_rows'       => 30,
        ]);
    }

    public function test_single_date_becomes_one_day_range_with_stays_overlap(): void
    {
        $captured = null;
        $beds24 = $this->mockBeds24Returns($captured, bookings: []);

        $this->action($beds24)->execute([
            'intent' => 'view_bookings',
            'dates'  => ['check_in' => '2026-05-05', 'check_out' => '2026-05-05'],
        ]);

        $this->assertSame('2026-05-05', $captured['arrivalTo']);
        $this->assertSame('2026-05-05', $captured['departureFrom']);
        $this->assertArrayNotHasKey('arrivalFrom', $captured);
    }

    public function test_single_day_query_triggers_sectioned_view(): void
    {
        // Phase 10.2: same filter call, but mode must be SECTIONED so the
        // formatter triages into Arriving / In-house / Departing.
        $captured = null;
        $bookings = [
            ['id' => 101, 'arrival' => '2026-05-05', 'departure' => '2026-05-08',
             'firstName' => 'Arr', 'lastName' => 'Guest', 'roomId' => '555', 'propertyId' => '41097', 'numAdult' => 2],
            ['id' => 201, 'arrival' => '2026-05-02', 'departure' => '2026-05-07',
             'firstName' => 'InH', 'lastName' => 'Guest', 'roomId' => '555', 'propertyId' => '41097', 'numAdult' => 2],
            ['id' => 301, 'arrival' => '2026-05-01', 'departure' => '2026-05-05',
             'firstName' => 'Dep', 'lastName' => 'Guest', 'roomId' => '555', 'propertyId' => '41097', 'numAdult' => 2],
        ];
        $beds24 = $this->mockBeds24Returns($captured, bookings: $bookings);

        $reply = $this->action($beds24)->execute([
            'intent' => 'view_bookings',
            'dates'  => ['check_in' => '2026-05-05', 'check_out' => '2026-05-05'],
        ]);

        $this->assertStringContainsString('🛬 Arriving', $reply);
        $this->assertStringContainsString('🏨 In-house', $reply);
        $this->assertStringContainsString('🛫 Departing', $reply);
    }

    public function test_multi_day_range_still_uses_grouped_stays_mode_not_sectioned(): void
    {
        $captured = null;
        $bookings = [
            ['id' => 1, 'arrival' => '2026-05-06', 'departure' => '2026-05-08',
             'firstName' => 'A', 'lastName' => 'B', 'roomId' => '555', 'propertyId' => '41097', 'numAdult' => 2],
        ];
        $beds24 = $this->mockBeds24Returns($captured, bookings: $bookings);

        $reply = $this->action($beds24)->execute([
            'intent' => 'view_bookings',
            'dates'  => ['check_in' => '2026-05-05', 'check_out' => '2026-05-08'],
        ]);

        // Multi-day: no section headings.
        $this->assertStringNotContainsString('🛬 Arriving', $reply);
        $this->assertStringNotContainsString('🏨 In-house', $reply);
        $this->assertStringNotContainsString('🛫 Departing', $reply);
        // But still shows header + row.
        $this->assertStringContainsString('Bookings 5 May → 8 May 2026 · 1', $reply);
    }

    public function test_range_within_cap_uses_stays_overlap_semantics(): void
    {
        $captured = null;
        $beds24 = $this->mockBeds24Returns($captured, bookings: []);

        $this->action($beds24)->execute([
            'intent' => 'view_bookings',
            'dates'  => ['check_in' => '2026-05-01', 'check_out' => '2026-05-10'],
        ]);

        // Stays-overlap filter: arrivalTo=end, departureFrom=start.
        $this->assertSame('2026-05-10', $captured['arrivalTo']);
        $this->assertSame('2026-05-01', $captured['departureFrom']);
    }

    public function test_range_over_cap_rejects_without_calling_beds24(): void
    {
        $beds24 = Mockery::mock(Beds24BookingService::class);
        $beds24->shouldNotReceive('getBookings');

        $reply = $this->action($beds24)->execute([
            'intent' => 'view_bookings',
            'dates'  => ['check_in' => '2026-01-01', 'check_out' => '2026-03-01'],
        ]);

        $this->assertStringContainsString('Range too large', $reply);
        $this->assertStringContainsString('max 31 days', $reply);
    }

    public function test_arrivals_with_dates_uses_arrival_window(): void
    {
        $captured = null;
        $beds24 = $this->mockBeds24Returns($captured, bookings: []);

        $this->action($beds24)->execute([
            'intent'      => 'view_bookings',
            'filter_type' => 'arrivals',
            'dates'       => ['check_in' => '2026-05-05', 'check_out' => '2026-05-10'],
        ]);

        $this->assertSame('2026-05-05', $captured['arrivalFrom']);
        $this->assertSame('2026-05-10', $captured['arrivalTo']);
        $this->assertArrayNotHasKey('departureFrom', $captured);
    }

    public function test_departures_with_dates_uses_departure_window(): void
    {
        $captured = null;
        $beds24 = $this->mockBeds24Returns($captured, bookings: []);

        $this->action($beds24)->execute([
            'intent'      => 'view_bookings',
            'filter_type' => 'departures',
            'dates'       => ['check_in' => '2026-05-05', 'check_out' => '2026-05-10'],
        ]);

        $this->assertSame('2026-05-05', $captured['departureFrom']);
        $this->assertSame('2026-05-10', $captured['departureTo']);
        $this->assertArrayNotHasKey('arrivalFrom', $captured);
    }

    public function test_today_shortcut_resolves_to_single_day_stays_overlap(): void
    {
        $captured = null;
        $beds24 = $this->mockBeds24Returns($captured, bookings: []);

        $this->action($beds24)->execute([
            'intent'      => 'view_bookings',
            'filter_type' => 'today',
        ]);

        $today = date('Y-m-d');
        $this->assertSame($today, $captured['arrivalTo']);
        $this->assertSame($today, $captured['departureFrom']);
    }

    public function test_arrivals_without_dates_rejects_with_operator_message(): void
    {
        $beds24 = Mockery::mock(Beds24BookingService::class);
        $beds24->shouldNotReceive('getBookings');

        $reply = $this->action($beds24)->execute([
            'intent'      => 'view_bookings',
            'filter_type' => 'arrivals',
        ]);

        $this->assertStringContainsString('date or range for arrivals', $reply);
    }

    public function test_arrivals_today_filter_type_preserved_for_backward_compat(): void
    {
        $captured = null;
        $beds24 = $this->mockBeds24Returns($captured, bookings: []);

        $reply = $this->action($beds24)->execute([
            'intent'      => 'view_bookings',
            'filter_type' => 'arrivals_today',
        ]);

        $today = date('Y-m-d');
        $this->assertSame($today, $captured['arrivalFrom']);
        $this->assertSame($today, $captured['arrivalTo']);
        $this->assertStringContainsString('Arrivals Today', $reply);
    }

    public function test_empty_result_copy(): void
    {
        $beds24 = $this->mockBeds24Returns($captured, bookings: []);

        $reply = $this->action($beds24)->execute([
            'intent' => 'view_bookings',
            'dates'  => ['check_in' => '2026-05-05', 'check_out' => '2026-05-05'],
        ]);

        $this->assertStringContainsString('No bookings found for Bookings 5 May 2026', $reply);
    }

    public function test_non_empty_result_includes_header_and_sorted_rows(): void
    {
        $bookings = [
            [
                'id' => 2, 'arrival' => '2026-05-06', 'departure' => '2026-05-08',
                'firstName' => 'Bob', 'lastName' => 'K', 'roomId' => '555', 'propertyId' => '41097',
                'numAdult' => 2, 'numChild' => 0, 'status' => 'confirmed',
            ],
            [
                'id' => 1, 'arrival' => '2026-05-05', 'departure' => '2026-05-07',
                'firstName' => 'Alice', 'lastName' => 'K', 'roomId' => '555', 'propertyId' => '41097',
                'numAdult' => 2, 'numChild' => 0, 'status' => 'confirmed',
            ],
        ];
        $beds24 = $this->mockBeds24Returns($captured, bookings: $bookings);

        $reply = $this->action($beds24)->execute([
            'intent' => 'view_bookings',
            'dates'  => ['check_in' => '2026-05-05', 'check_out' => '2026-05-08'],
        ]);

        // Phase 9.2 compact header: "{title} · {count}".
        $this->assertStringContainsString('Bookings 5 May → 8 May 2026 · 2', $reply);
        $this->assertLessThan(strpos($reply, '#2'), strpos($reply, '#1')); // sorted asc
    }

    private function action(Beds24BookingService $beds24): ViewBookingsFromMessageAction
    {
        return new ViewBookingsFromMessageAction($beds24, new BookingListFormatter());
    }

    /**
     * @param array<string,mixed>|null $captured Passed by reference; filled with the filters Beds24 received.
     * @param array<int, array<string, mixed>> $bookings
     */
    private function mockBeds24Returns(&$captured, array $bookings): Beds24BookingService&MockInterface
    {
        /** @var Beds24BookingService&MockInterface $mock */
        $mock = Mockery::mock(Beds24BookingService::class);
        $mock->shouldReceive('getBookings')
            ->andReturnUsing(function (array $filters) use (&$captured, $bookings): array {
                $captured = $filters;
                return [
                    'success' => true,
                    'count'   => count($bookings),
                    'data'    => $bookings,
                ];
            });
        return $mock;
    }
}
