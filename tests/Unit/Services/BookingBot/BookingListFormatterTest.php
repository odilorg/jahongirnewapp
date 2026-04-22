<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BookingBot;

use App\Models\RoomUnitMapping;
use App\Services\BookingBot\BookingListFormatter;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Pure-unit tests for BookingListFormatter (Phase 9.2 compact rows).
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

    public function test_header_uses_count_without_parentheses_phrase(): void
    {
        $out = $this->fmt->format(
            [$this->booking(1, '2026-05-05', '2026-05-07')],
            'Bookings 5-7 May 2026',
            $this->rooms(),
        );
        $this->assertStringContainsString('Bookings 5-7 May 2026 · 1', $out);
        $this->assertStringNotContainsString('found', $out);
    }

    public function test_sorts_ascending_by_arrival_in_stays_mode(): void
    {
        $bookings = [
            $this->booking(3, '2026-05-07', '2026-05-10', firstName: 'C'),
            $this->booking(1, '2026-05-03', '2026-05-06', firstName: 'A'),
            $this->booking(2, '2026-05-05', '2026-05-08', firstName: 'B'),
        ];
        $out = $this->fmt->format($bookings, 'Bookings', $this->rooms(), BookingListFormatter::MODE_STAYS);

        $pos1 = strpos($out, '#1');
        $pos2 = strpos($out, '#2');
        $pos3 = strpos($out, '#3');
        $this->assertLessThan($pos2, $pos1);
        $this->assertLessThan($pos3, $pos2);
    }

    public function test_group_collapse_same_guest_same_dates_same_property_becomes_one_row(): void
    {
        $bookings = [
            $this->booking(101, '2026-04-20', '2026-04-23', firstName: 'Orient', lastName: 'Insight', roomId: '555'),
            $this->booking(102, '2026-04-20', '2026-04-23', firstName: 'Orient', lastName: 'Insight', roomId: '556'),
            $this->booking(103, '2026-04-20', '2026-04-23', firstName: 'Orient', lastName: 'Insight', roomId: '557'),
            $this->booking(104, '2026-04-20', '2026-04-23', firstName: 'Orient', lastName: 'Insight', roomId: '558'),
        ];
        $out = $this->fmt->format($bookings, 'Bookings', $this->rooms(), BookingListFormatter::MODE_STAYS);

        // Name is abbreviated to O.Insight; exactly one row should appear.
        $this->assertSame(1, substr_count($out, 'O.Insight'), 'only one collapsed row for the group');
        $this->assertStringContainsString('×4', $out);
        $this->assertStringContainsString('#101', $out);
        // All four units present in the collapsed row.
        $this->assertStringContainsString('12,14,10,5', $out);
        $this->assertStringNotContainsString('#102', $out);
        $this->assertStringNotContainsString('#103', $out);
        $this->assertMatchesRegularExpression('/Mon 20 Apr \(4\)/', $out);
    }

    public function test_group_collapse_different_dates_NOT_merged(): void
    {
        $bookings = [
            $this->booking(201, '2026-05-01', '2026-05-03', firstName: 'Orient', lastName: 'Insight', roomId: '555'),
            $this->booking(202, '2026-05-05', '2026-05-07', firstName: 'Orient', lastName: 'Insight', roomId: '555'),
        ];
        $out = $this->fmt->format($bookings, 'Bookings', $this->rooms());

        $this->assertStringContainsString('#201', $out);
        $this->assertStringContainsString('#202', $out);
        $this->assertStringNotContainsString('×2', $out);
    }

    public function test_name_abbreviation_multi_given_name(): void
    {
        $bookings = [$this->booking(1, '2026-05-05', '2026-05-07', firstName: 'Jose Miguel Frances', lastName: 'Hierro')];
        $out = $this->fmt->format($bookings, 'Bookings', $this->rooms());
        $this->assertStringContainsString('J.M.F.Hierro', $out);
        $this->assertStringNotContainsString('Jose Miguel', $out);
    }

    public function test_name_abbreviation_slash_suffix_trimmed(): void
    {
        $bookings = [$this->booking(1, '2026-05-05', '2026-05-07', firstName: 'Jacques TURRI', lastName: '/Airport transfer 12 $/ 7,30 am / 19.04.26')];
        $out = $this->fmt->format($bookings, 'Bookings', $this->rooms());
        $this->assertStringContainsString('J.TURRI /Airport', $out);
        $this->assertStringNotContainsString('transfer', $out);
        $this->assertStringNotContainsString('7,30', $out);
    }

    public function test_confirmed_status_is_not_rendered(): void
    {
        $bookings = [$this->booking(1, '2026-05-05', '2026-05-07', status: 'confirmed')];
        $out = $this->fmt->format($bookings, 'Bookings', $this->rooms());
        $this->assertStringNotContainsString('Confirmed', $out);
        $this->assertStringNotContainsString('✅', $out);
    }

    public function test_non_default_status_is_rendered_with_icon(): void
    {
        $req    = [$this->booking(1, '2026-05-05', '2026-05-07', status: 'request')];
        $newB   = [$this->booking(2, '2026-05-05', '2026-05-07', status: 'new')];
        $cancel = [$this->booking(3, '2026-05-05', '2026-05-07', status: 'cancelled')];

        $this->assertStringContainsString('❓', $this->fmt->format($req,    'x', $this->rooms()));
        $this->assertStringContainsString('🆕', $this->fmt->format($newB,   'x', $this->rooms()));
        $this->assertStringContainsString('❌', $this->fmt->format($cancel, 'x', $this->rooms()));
    }

    public function test_nights_shown_only_when_two_or_more(): void
    {
        $one = [$this->booking(1, '2026-05-05', '2026-05-06')];
        $two = [$this->booking(2, '2026-05-05', '2026-05-07')];
        $this->assertStringNotContainsString('1n', $this->fmt->format($one, 'x', $this->rooms()));
        $this->assertStringContainsString('2n', $this->fmt->format($two, 'x', $this->rooms()));
    }

    public function test_property_shorthand_only_when_mixed(): void
    {
        $singleProp = [
            $this->booking(1, '2026-05-05', '2026-05-07', roomId: '555', propertyId: '41097'),
            $this->booking(2, '2026-05-05', '2026-05-07', roomId: '556', propertyId: '41097', firstName: 'Z'),
        ];
        $outSingle = $this->fmt->format($singleProp, 'x', $this->rooms());
        $this->assertStringNotContainsString('Hotel', $outSingle);
        $this->assertStringNotContainsString('Prem', $outSingle);

        $mixed = [
            $this->booking(1, '2026-05-05', '2026-05-07', roomId: '555', propertyId: '41097'),
            $this->booking(2, '2026-05-05', '2026-05-07', roomId: '777', propertyId: '172793', firstName: 'Z'),
        ];
        $outMixed = $this->fmt->format($mixed, 'x', $this->rooms());
        $this->assertStringContainsString('Hotel', $outMixed);
        $this->assertStringContainsString('Prem', $outMixed);
    }

    public function test_groups_by_arrival_in_arrivals_mode(): void
    {
        $bookings = [
            $this->booking(1, '2026-05-05', '2026-05-07'),
            $this->booking(2, '2026-05-06', '2026-05-08', firstName: 'Z'),
        ];
        $out = $this->fmt->format($bookings, 'Arrivals', $this->rooms(), BookingListFormatter::MODE_ARRIVALS);
        $this->assertStringContainsString('Tue 5 May', $out);
        $this->assertStringContainsString('Wed 6 May', $out);
    }

    public function test_groups_by_departure_in_departures_mode(): void
    {
        $bookings = [
            $this->booking(1, '2026-05-01', '2026-05-05'),
            $this->booking(2, '2026-05-02', '2026-05-06', firstName: 'Z'),
        ];
        $out = $this->fmt->format($bookings, 'Departures', $this->rooms(), BookingListFormatter::MODE_DEPARTURES);
        $this->assertStringContainsString('Tue 5 May', $out);
        $this->assertStringContainsString('Wed 6 May', $out);
    }

    public function test_caps_rows_and_appends_overflow_line_after_collapse(): void
    {
        config(['hotel_booking_bot.view.max_rows' => 3]);

        $bookings = [];
        for ($i = 1; $i <= 5; $i++) {
            $bookings[] = $this->booking($i, '2026-05-0' . $i, '2026-05-0' . ($i + 1), firstName: 'Guest' . $i);
        }
        $out = $this->fmt->format($bookings, 'Bookings', $this->rooms(), BookingListFormatter::MODE_STAYS);

        $this->assertStringContainsString('· 5', $out);
        $this->assertStringContainsString('+2 more (narrow your query)', $out);
        $this->assertStringNotContainsString('#4', $out);
        $this->assertStringNotContainsString('#5', $out);
    }

    public function test_stays_overlap_includes_crossing_bookings(): void
    {
        $bookings = [$this->booking(42, '2026-05-03', '2026-05-07')];
        $out = $this->fmt->format($bookings, 'Bookings 5 May 2026', $this->rooms(), BookingListFormatter::MODE_STAYS);
        $this->assertStringContainsString('#42', $out);
        $this->assertStringContainsString('4n', $out);
    }

    /**
     * @return array<string, mixed>
     */
    private function booking(
        int|string $id,
        string $arrival,
        string $departure,
        string $firstName = 'Jane',
        string $lastName = 'Doe',
        string $roomId = '555',
        string $propertyId = '41097',
        ?string $status = null,
    ): array {
        $b = [
            'id'         => $id,
            'arrival'    => $arrival,
            'departure'  => $departure,
            'firstName'  => $firstName,
            'lastName'   => $lastName,
            'roomId'     => $roomId,
            'propertyId' => $propertyId,
            'numAdult'   => 2,
            'numChild'   => 0,
        ];
        if ($status !== null) {
            $b['status'] = $status;
        }
        return $b;
    }

    /** @return Collection<int, RoomUnitMapping> */
    private function rooms(): Collection
    {
        $out = [];
        foreach ([['12', '555'], ['14', '556'], ['10', '557'], ['5', '558']] as $pair) {
            $m = new RoomUnitMapping();
            $m->unit_name     = $pair[0];
            $m->property_id   = '41097';
            $m->property_name = 'Jahongir Hotel';
            $m->room_id       = $pair[1];
            $m->room_name     = 'Double ' . $pair[0];
            $m->room_type     = 'double';
            $m->max_guests    = 2;
            $out[] = $m;
        }
        $prem = new RoomUnitMapping();
        $prem->unit_name     = '21';
        $prem->property_id   = '172793';
        $prem->property_name = 'Jahongir Premium';
        $prem->room_id       = '777';
        $prem->room_name     = 'Suite';
        $prem->room_type     = 'suite';
        $prem->max_guests    = 2;
        $out[] = $prem;

        return new Collection($out);
    }
}
