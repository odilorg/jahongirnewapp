<?php

namespace Tests\Feature\Stay;

use App\Models\Beds24Booking;
use App\Services\Stay\BookingSummary;
use App\Services\Stay\StayListService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StayListServiceTest extends TestCase
{
    use RefreshDatabase;

    private StayListService $service;
    private string $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new StayListService();
        $this->today   = now()->toDateString();
    }

    // =========================================================================
    // Arrivals Today
    // =========================================================================

    public function test_arrivals_returns_confirmed_booking_arriving_today(): void
    {
        Beds24Booking::factory()->create([
            'arrival_date'   => $this->today,
            'booking_status' => 'confirmed',
        ]);

        $results = $this->service->getArrivalsToday();

        $this->assertCount(1, $results);
        $this->assertInstanceOf(BookingSummary::class, $results->first());
    }

    public function test_arrivals_returns_new_status_booking_arriving_today(): void
    {
        Beds24Booking::factory()->create([
            'arrival_date'   => $this->today,
            'booking_status' => 'new',
        ]);

        $results = $this->service->getArrivalsToday();

        $this->assertCount(1, $results);
    }

    public function test_arrivals_excludes_cancelled(): void
    {
        Beds24Booking::factory()->create([
            'arrival_date'   => $this->today,
            'booking_status' => 'cancelled',
        ]);

        $this->assertCount(0, $this->service->getArrivalsToday());
    }

    public function test_arrivals_excludes_no_show(): void
    {
        Beds24Booking::factory()->create([
            'arrival_date'   => $this->today,
            'booking_status' => 'no_show',
        ]);

        $this->assertCount(0, $this->service->getArrivalsToday());
    }

    public function test_arrivals_excludes_already_checked_in(): void
    {
        Beds24Booking::factory()->create([
            'arrival_date'   => $this->today,
            'booking_status' => 'checked_in',
        ]);

        $this->assertCount(0, $this->service->getArrivalsToday());
    }

    public function test_arrivals_excludes_already_checked_out(): void
    {
        Beds24Booking::factory()->create([
            'arrival_date'   => $this->today,
            'booking_status' => 'checked_out',
        ]);

        $this->assertCount(0, $this->service->getArrivalsToday());
    }

    public function test_arrivals_excludes_bookings_not_arriving_today(): void
    {
        Beds24Booking::factory()->create([
            'arrival_date'   => now()->addDay()->toDateString(),
            'booking_status' => 'confirmed',
        ]);

        $this->assertCount(0, $this->service->getArrivalsToday());
    }

    public function test_arrivals_filters_by_property_id(): void
    {
        Beds24Booking::factory()->create([
            'arrival_date'   => $this->today,
            'booking_status' => 'confirmed',
            'property_id'    => '41097',
        ]);
        Beds24Booking::factory()->create([
            'arrival_date'   => $this->today,
            'booking_status' => 'confirmed',
            'property_id'    => '172793',
        ]);

        $results = $this->service->getArrivalsToday('41097');

        $this->assertCount(1, $results);
        $this->assertSame('41097', Beds24Booking::where('beds24_booking_id', $results->first()->beds24BookingId)->value('property_id'));
    }

    public function test_arrivals_are_ordered_by_room_name_then_booking_id(): void
    {
        Beds24Booking::factory()->create([
            'arrival_date'   => $this->today,
            'booking_status' => 'confirmed',
            'room_name'      => 'Single Room 9',
            'beds24_booking_id' => 'B999',
        ]);
        Beds24Booking::factory()->create([
            'arrival_date'   => $this->today,
            'booking_status' => 'confirmed',
            'room_name'      => 'Double Room 2',
            'beds24_booking_id' => 'B111',
        ]);
        Beds24Booking::factory()->create([
            'arrival_date'   => $this->today,
            'booking_status' => 'confirmed',
            'room_name'      => 'Double Room 2',
            'beds24_booking_id' => 'B222',
        ]);

        $results = $this->service->getArrivalsToday();

        $this->assertSame(['B111', 'B222', 'B999'], $results->pluck('beds24BookingId')->all());
    }

    // =========================================================================
    // Departures Today
    // =========================================================================

    public function test_departures_returns_checked_in_booking_departing_today(): void
    {
        Beds24Booking::factory()->create([
            'departure_date' => $this->today,
            'booking_status' => 'checked_in',
        ]);

        $results = $this->service->getDeparturesToday();

        $this->assertCount(1, $results);
    }

    public function test_departures_returns_confirmed_booking_departing_today(): void
    {
        // Same-day arrival/departure or bot check-in not yet done
        Beds24Booking::factory()->create([
            'departure_date' => $this->today,
            'booking_status' => 'confirmed',
        ]);

        $this->assertCount(1, $this->service->getDeparturesToday());
    }

    public function test_departures_excludes_cancelled(): void
    {
        Beds24Booking::factory()->create([
            'departure_date' => $this->today,
            'booking_status' => 'cancelled',
        ]);

        $this->assertCount(0, $this->service->getDeparturesToday());
    }

    public function test_departures_excludes_no_show(): void
    {
        Beds24Booking::factory()->create([
            'departure_date' => $this->today,
            'booking_status' => 'no_show',
        ]);

        $this->assertCount(0, $this->service->getDeparturesToday());
    }

    public function test_departures_excludes_already_checked_out(): void
    {
        Beds24Booking::factory()->create([
            'departure_date' => $this->today,
            'booking_status' => 'checked_out',
        ]);

        $this->assertCount(0, $this->service->getDeparturesToday());
    }

    public function test_departures_excludes_bookings_not_departing_today(): void
    {
        Beds24Booking::factory()->create([
            'departure_date' => now()->addDay()->toDateString(),
            'booking_status' => 'checked_in',
        ]);

        $this->assertCount(0, $this->service->getDeparturesToday());
    }

    public function test_departures_filters_by_property_id(): void
    {
        Beds24Booking::factory()->create([
            'departure_date' => $this->today,
            'booking_status' => 'checked_in',
            'property_id'    => '41097',
        ]);
        Beds24Booking::factory()->create([
            'departure_date' => $this->today,
            'booking_status' => 'checked_in',
            'property_id'    => '172793',
        ]);

        $this->assertCount(1, $this->service->getDeparturesToday('41097'));
    }

    public function test_departures_are_ordered_by_room_name_then_booking_id(): void
    {
        Beds24Booking::factory()->create([
            'departure_date' => $this->today,
            'booking_status' => 'checked_in',
            'room_name'      => 'Twin Room 7',
            'beds24_booking_id' => 'C999',
        ]);
        Beds24Booking::factory()->create([
            'departure_date' => $this->today,
            'booking_status' => 'checked_in',
            'room_name'      => 'Family Room 15',
            'beds24_booking_id' => 'C111',
        ]);

        $results = $this->service->getDeparturesToday();

        $this->assertSame(['C111', 'C999'], $results->pluck('beds24BookingId')->all());
    }

    // =========================================================================
    // BookingSummary shape
    // =========================================================================

    public function test_summary_contains_required_fields(): void
    {
        Beds24Booking::factory()->create([
            'arrival_date'      => $this->today,
            'departure_date'    => now()->addDays(2)->toDateString(),
            'booking_status'    => 'confirmed',
            'guest_name'        => 'John Doe',
            'room_name'         => 'Double Room 11',
            'property_id'       => '41097',
            'invoice_balance'   => 150.00,
            'currency'          => 'USD',
            'num_adults'        => 2,
            'num_children'      => 0,
        ]);

        $summary = $this->service->getArrivalsToday()->first();

        $this->assertNotEmpty($summary->beds24BookingId);
        $this->assertSame('John Doe', $summary->guestName);
        $this->assertSame('Double Room 11', $summary->roomName);
        $this->assertSame('Jahongir Hotel', $summary->propertyName);
        $this->assertSame($this->today, $summary->arrivalDate);
        $this->assertSame(now()->addDays(2)->toDateString(), $summary->departureDate);
        $this->assertSame('confirmed', $summary->bookingStatus);
        $this->assertSame(150.0, $summary->invoiceBalance);
        $this->assertSame('USD', $summary->currency);
        $this->assertSame(2, $summary->numAdults);
        $this->assertSame(0, $summary->numChildren);
    }

    public function test_summary_falls_back_to_unknown_guest_when_name_absent(): void
    {
        Beds24Booking::factory()->create([
            'arrival_date'   => $this->today,
            'booking_status' => 'confirmed',
            'guest_name'     => '',
        ]);

        $summary = $this->service->getArrivalsToday()->first();

        $this->assertSame('Unknown Guest', $summary->guestName);
    }
}
