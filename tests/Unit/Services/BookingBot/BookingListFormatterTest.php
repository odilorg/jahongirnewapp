<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BookingBot;

use App\Models\RoomUnitMapping;
use App\Services\BookingBot\BookingListFormatter;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Pure-unit tests for BookingListFormatter. No DB — in-memory models.
 */
final class BookingListFormatterTest extends TestCase
{
    private BookingListFormatter $fmt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fmt = new BookingListFormatter();
        config(['hotel_booking_bot.view.max_rows' => 30]);
    }

    public function test_empty_state_copy(): void
    {
        $out = $this->fmt->format([], 'Bookings 5 May 2026', $this->rooms());
        $this->assertSame('No bookings found for Bookings 5 May 2026.', $out);
    }

    public function test_header_includes_count(): void
    {
        $out = $this->fmt->format(
            [$this->booking(1, '2026-05-05', '2026-05-07')],
            'Bookings 5-7 May 2026',
            $this->rooms(),
        );
        $this->assertStringContainsString('Bookings 5-7 May 2026 (1 found)', $out);
    }

    public function test_sorts_ascending_by_arrival_in_stays_mode(): void
    {
        $bookings = [
            $this->booking(3, '2026-05-07', '2026-05-10'),
            $this->booking(1, '2026-05-03', '2026-05-06'),
            $this->booking(2, '2026-05-05', '2026-05-08'),
        ];
        $out = $this->fmt->format($bookings, 'Bookings', $this->rooms(), BookingListFormatter::MODE_STAYS);

        $pos1 = strpos($out, '#1');
        $pos2 = strpos($out, '#2');
        $pos3 = strpos($out, '#3');
        $this->assertLessThan($pos2, $pos1);
        $this->assertLessThan($pos3, $pos2);
    }

    public function test_groups_by_arrival_in_arrivals_mode(): void
    {
        $bookings = [
            $this->booking(1, '2026-05-05', '2026-05-07'),
            $this->booking(2, '2026-05-05', '2026-05-06'),
            $this->booking(3, '2026-05-06', '2026-05-10'),
        ];
        $out = $this->fmt->format($bookings, 'Arrivals', $this->rooms(), BookingListFormatter::MODE_ARRIVALS);

        $this->assertStringContainsString('— Tue, 5 May 2026 —', $out);
        $this->assertStringContainsString('— Wed, 6 May 2026 —', $out);
        // May 5 group must come before May 6 group.
        $this->assertLessThan(
            strpos($out, '— Wed, 6 May 2026 —'),
            strpos($out, '— Tue, 5 May 2026 —'),
        );
    }

    public function test_groups_by_departure_in_departures_mode(): void
    {
        $bookings = [
            $this->booking(1, '2026-05-01', '2026-05-05'),
            $this->booking(2, '2026-05-02', '2026-05-06'),
        ];
        $out = $this->fmt->format($bookings, 'Departures', $this->rooms(), BookingListFormatter::MODE_DEPARTURES);

        $this->assertStringContainsString('— Tue, 5 May 2026 —', $out);
        $this->assertStringContainsString('— Wed, 6 May 2026 —', $out);
    }

    public function test_caps_rows_and_appends_overflow_line(): void
    {
        config(['hotel_booking_bot.view.max_rows' => 3]);

        $bookings = [];
        for ($i = 1; $i <= 5; $i++) {
            $bookings[] = $this->booking($i, '2026-05-0' . $i, '2026-05-0' . ($i + 1));
        }

        $out = $this->fmt->format($bookings, 'Bookings', $this->rooms(), BookingListFormatter::MODE_STAYS);

        $this->assertStringContainsString('(5 found)', $out);
        $this->assertStringContainsString('+2 more (narrow your query)', $out);
        // #4 and #5 must NOT appear.
        $this->assertStringNotContainsString('#4', $out);
        $this->assertStringNotContainsString('#5', $out);
    }

    public function test_stays_overlap_includes_crossing_bookings(): void
    {
        // Rule A: a booking staying May 3–7 must appear in a "May 5" query.
        // The Beds24 API does the date filtering; here we only verify
        // that once such a row is handed to the formatter, it renders.
        $bookings = [
            $this->booking(42, '2026-05-03', '2026-05-07'),
        ];
        $out = $this->fmt->format($bookings, 'Bookings 5 May 2026', $this->rooms(), BookingListFormatter::MODE_STAYS);

        $this->assertStringContainsString('#42', $out);
        $this->assertStringContainsString('4n', $out); // 4-night stay
    }

    public function test_mixed_property_rows_are_prefixed_with_property_name(): void
    {
        $bookings = [
            $this->booking(1, '2026-05-01', '2026-05-03', roomId: '555', propertyId: '41097'),
            $this->booking(2, '2026-05-02', '2026-05-04', roomId: '777', propertyId: '172793'),
        ];
        $out = $this->fmt->format($bookings, 'Bookings', $this->rooms(), BookingListFormatter::MODE_STAYS);

        $this->assertStringContainsString('Jahongir Hotel · 12', $out);
        $this->assertStringContainsString('Jahongir Premium · 21', $out);
    }

    public function test_single_property_rows_use_compact_unit_label(): void
    {
        $bookings = [
            $this->booking(1, '2026-05-01', '2026-05-03', roomId: '555', propertyId: '41097'),
            $this->booking(2, '2026-05-02', '2026-05-04', roomId: '555', propertyId: '41097'),
        ];
        $out = $this->fmt->format($bookings, 'Bookings', $this->rooms(), BookingListFormatter::MODE_STAYS);

        $this->assertStringNotContainsString('Jahongir Hotel · 12', $out);
        $this->assertStringContainsString('12 — Double A', $out);
    }

    public function test_status_emoji_rendered_when_present(): void
    {
        $b = $this->booking(1, '2026-05-05', '2026-05-07');
        $b['status'] = 'confirmed';
        $out = $this->fmt->format([$b], 'Bookings', $this->rooms());
        $this->assertStringContainsString('✅', $out);
        $this->assertStringContainsString('Confirmed', $out);
    }

    /**
     * @return array<string, mixed>
     */
    private function booking(
        int|string $id,
        string $arrival,
        string $departure,
        string $roomId = '555',
        string $propertyId = '41097',
    ): array {
        return [
            'id'         => $id,
            'arrival'    => $arrival,
            'departure'  => $departure,
            'firstName'  => 'Jane',
            'lastName'   => 'Doe',
            'roomId'     => $roomId,
            'propertyId' => $propertyId,
            'numAdult'   => 2,
            'numChild'   => 0,
        ];
    }

    /** @return Collection<int, RoomUnitMapping> */
    private function rooms(): Collection
    {
        $hotel         = new RoomUnitMapping();
        $hotel->unit_name     = '12';
        $hotel->property_id   = '41097';
        $hotel->property_name = 'Jahongir Hotel';
        $hotel->room_id       = '555';
        $hotel->room_name     = 'Double A';
        $hotel->room_type     = 'double';
        $hotel->max_guests    = 2;

        $premium       = new RoomUnitMapping();
        $premium->unit_name     = '21';
        $premium->property_id   = '172793';
        $premium->property_name = 'Jahongir Premium';
        $premium->room_id       = '777';
        $premium->room_name     = 'Suite';
        $premium->room_type     = 'suite';
        $premium->max_guests    = 2;

        return new Collection([$hotel, $premium]);
    }
}
