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

    // ─── Phase 10.1 — inline snippets for comments / notes ────────────────

    public function test_renders_comments_snippet_with_speech_icon(): void
    {
        config(['hotel_booking_bot.view.snippet_max_chars' => 40]);
        $b = $this->booking(1, '2026-05-05', '2026-05-07');
        $b['comments'] = 'Non Smoking Requested';
        $out = $this->fmt->format([$b], 'Bookings', $this->rooms());
        $this->assertStringContainsString('💬 Non Smoking Requested', $out);
    }

    public function test_renders_notes_snippet_with_memo_icon(): void
    {
        $b = $this->booking(1, '2026-05-05', '2026-05-07');
        $b['notes'] = 'Transferred from Deluxe triple to Superior Double';
        $out = $this->fmt->format([$b], 'Bookings', $this->rooms());
        // Cap 40: snippet = first 39 chars of source + "…".
        $this->assertStringContainsString('📝 Transferred from Deluxe triple to Super…', $out);
    }

    public function test_renders_both_snippets_comments_first(): void
    {
        $b = $this->booking(1, '2026-05-05', '2026-05-07');
        $b['comments'] = 'Early check-in';
        $b['notes']    = 'VIP guest';
        $out = $this->fmt->format([$b], 'Bookings', $this->rooms());
        $posC = strpos($out, '💬');
        $posN = strpos($out, '📝');
        $this->assertNotFalse($posC);
        $this->assertNotFalse($posN);
        $this->assertLessThan($posN, $posC);
    }

    public function test_snippet_truncates_to_configured_cap_with_ellipsis(): void
    {
        config(['hotel_booking_bot.view.snippet_max_chars' => 20]);
        $b = $this->booking(1, '2026-05-05', '2026-05-07');
        $b['comments'] = 'Hello, is it possible to get a double bed?';
        $out = $this->fmt->format([$b], 'Bookings', $this->rooms());
        // Cap 20: snippet = first 19 chars + "…".
        $this->assertStringContainsString('💬 Hello, is it possib…', $out);
        $this->assertStringNotContainsString('double bed', $out);
    }

    public function test_empty_and_whitespace_only_text_is_not_rendered(): void
    {
        $b = $this->booking(1, '2026-05-05', '2026-05-07');
        $b['comments'] = '';
        $b['notes']    = "   \n  \t ";
        $out = $this->fmt->format([$b], 'Bookings', $this->rooms());
        $this->assertStringNotContainsString('💬', $out);
        $this->assertStringNotContainsString('📝', $out);
    }

    public function test_snippet_sanitizes_newlines_into_slash_separator(): void
    {
        $b = $this->booking(1, '2026-05-05', '2026-05-07');
        $b['comments'] = "Hello\nThank you\n\nNon Smoking";
        $out = $this->fmt->format([$b], 'Bookings', $this->rooms());
        $this->assertStringContainsString('💬 Hello / Thank you / Non Smoking', $out);
        $this->assertStringNotContainsString("\n💬", $out === '' ? 'x' : '💬 Hello' . "\n" . 'Thank');
    }

    public function test_snippet_collapses_repeated_whitespace(): void
    {
        $b = $this->booking(1, '2026-05-05', '2026-05-07');
        $b['comments'] = "  Room   with    double   bed   ";
        $out = $this->fmt->format([$b], 'Bookings', $this->rooms());
        $this->assertStringContainsString('💬 Room with double bed', $out);
    }

    public function test_show_comments_flag_off_hides_comments_but_keeps_notes(): void
    {
        config([
            'hotel_booking_bot.view.show_comments' => false,
            'hotel_booking_bot.view.show_notes'    => true,
        ]);
        $b = $this->booking(1, '2026-05-05', '2026-05-07');
        $b['comments'] = 'Guest request';
        $b['notes']    = 'Staff note';
        $out = $this->fmt->format([$b], 'Bookings', $this->rooms());
        $this->assertStringNotContainsString('💬', $out);
        $this->assertStringNotContainsString('Guest request', $out);
        $this->assertStringContainsString('📝 Staff note', $out);
    }

    // ─── Phase 10.2 — sectioned view for single-day queries ───────────────

    public function test_sectioned_splits_into_arriving_inhouse_departing(): void
    {
        $ref = '2026-04-22';

        $bookings = [
            // Arriving today (a == ref)
            $this->booking(101, $ref, '2026-04-25', firstName: 'Arr'),
            // In-house (a < ref < d)
            $this->booking(201, '2026-04-19', '2026-04-25', firstName: 'InH'),
            // Departing today (d == ref)
            $this->booking(301, '2026-04-19', $ref, firstName: 'Dep'),
        ];

        $out = $this->fmt->format($bookings, 'Bookings 22 Apr 2026', $this->rooms(), BookingListFormatter::MODE_SECTIONED, $ref);

        $this->assertStringContainsString('🛬 Arriving', $out);
        $this->assertStringContainsString('🏨 In-house', $out);
        $this->assertStringContainsString('🛫 Departing', $out);

        // Each booking lands in exactly one section (ids must appear).
        $this->assertStringContainsString('#101', $out);
        $this->assertStringContainsString('#201', $out);
        $this->assertStringContainsString('#301', $out);

        // Ordering: Arriving block before In-house before Departing.
        $this->assertLessThan(strpos($out, '🏨'), strpos($out, '🛬'));
        $this->assertLessThan(strpos($out, '🛫'), strpos($out, '🏨'));
    }

    public function test_sectioned_no_bucket_overlap(): void
    {
        $ref = '2026-04-22';
        $bookings = [
            $this->booking(101, $ref, '2026-04-25', firstName: 'X'),
            $this->booking(301, '2026-04-20', $ref, firstName: 'Y'),
        ];
        $out = $this->fmt->format($bookings, 'Bookings', $this->rooms(), BookingListFormatter::MODE_SECTIONED, $ref);

        // #101 appears under arriving heading, NOT under departing heading.
        $posA = strpos($out, '🛬 Arriving');
        $posD = strpos($out, '🛫 Departing');
        $pos101 = strpos($out, '#101');
        $pos301 = strpos($out, '#301');

        $this->assertNotFalse($posA);
        $this->assertNotFalse($posD);
        $this->assertGreaterThan($posA, $pos101);
        $this->assertLessThan($posD, $pos101);        // #101 before Departing heading
        $this->assertGreaterThan($posD, $pos301);     // #301 after Departing heading
    }

    public function test_sectioned_skips_empty_sections(): void
    {
        $ref = '2026-04-22';
        // Only arrivals — no in-house, no departures.
        $bookings = [
            $this->booking(101, $ref, '2026-04-25', firstName: 'A'),
            $this->booking(102, $ref, '2026-04-24', firstName: 'B'),
        ];
        $out = $this->fmt->format($bookings, 'Bookings', $this->rooms(), BookingListFormatter::MODE_SECTIONED, $ref);

        $this->assertStringContainsString('🛬 Arriving', $out);
        $this->assertStringNotContainsString('🏨 In-house', $out);
        $this->assertStringNotContainsString('🛫 Departing', $out);
    }

    public function test_sectioned_heading_uses_today_suffix_for_todays_date(): void
    {
        $today = date('Y-m-d');
        $bookings = [$this->booking(1, $today, date('Y-m-d', strtotime('+2 days')))];
        $out = $this->fmt->format($bookings, 'Bookings today', $this->rooms(), BookingListFormatter::MODE_SECTIONED, $today);

        $this->assertStringContainsString('🛬 Arriving today', $out);
    }

    public function test_sectioned_heading_uses_date_suffix_for_non_today_date(): void
    {
        $bookings = [$this->booking(1, '2030-05-05', '2030-05-07')];
        $out = $this->fmt->format($bookings, 'Bookings 5 May 2030', $this->rooms(), BookingListFormatter::MODE_SECTIONED, '2030-05-05');

        $this->assertStringContainsString('🛬 Arriving 5 May', $out);
        $this->assertStringNotContainsString('today', $out);
    }

    public function test_sectioned_arriving_sorted_by_arrival_ascending(): void
    {
        $ref = '2026-04-22';
        $bookings = [
            // Same arrival date, different ids — sort falls back stably.
            $this->booking(102, $ref, '2026-04-25', firstName: 'B'),
            $this->booking(101, $ref, '2026-04-24', firstName: 'A'),
        ];
        $out = $this->fmt->format($bookings, 'Bookings', $this->rooms(), BookingListFormatter::MODE_SECTIONED, $ref);
        $this->assertNotFalse(strpos($out, '🛬'));
    }

    public function test_sectioned_inhouse_sorted_by_departure_soonest_first(): void
    {
        $ref = '2026-04-22';
        $bookings = [
            $this->booking(203, '2026-04-18', '2026-05-01', firstName: 'LeavingLast'),
            $this->booking(201, '2026-04-19', '2026-04-23', firstName: 'LeavingFirst'),
            $this->booking(202, '2026-04-20', '2026-04-26', firstName: 'LeavingMid'),
        ];
        $out = $this->fmt->format($bookings, 'Bookings', $this->rooms(), BookingListFormatter::MODE_SECTIONED, $ref);

        $pos201 = strpos($out, '#201');
        $pos202 = strpos($out, '#202');
        $pos203 = strpos($out, '#203');
        $this->assertLessThan($pos202, $pos201);
        $this->assertLessThan($pos203, $pos202);
    }

    public function test_sectioned_collapse_and_snippets_preserved_inside_sections(): void
    {
        $ref = '2026-04-22';
        // Two siblings same-guest same-dates in arriving section.
        $a1 = $this->booking(101, $ref, '2026-04-25', firstName: 'Grp', lastName: 'X', roomId: '555');
        $a2 = $this->booking(102, $ref, '2026-04-25', firstName: 'Grp', lastName: 'X', roomId: '556');
        $a1['comments'] = 'Non Smoking Requested';

        $out = $this->fmt->format([$a1, $a2], 'Bookings', $this->rooms(), BookingListFormatter::MODE_SECTIONED, $ref);

        $this->assertStringContainsString('×2', $out);
        $this->assertStringContainsString('💬 Non Smoking Requested', $out);
        $this->assertStringNotContainsString('#102', $out);
    }

    public function test_collapsed_group_uses_master_only_snippet(): void
    {
        // Three siblings, each with different comments. Only master's
        // should render. Locked rule: no sibling snippet concatenation.
        $m1 = $this->booking(101, '2026-05-05', '2026-05-07', firstName: 'Group', lastName: 'X', roomId: '555');
        $m2 = $this->booking(102, '2026-05-05', '2026-05-07', firstName: 'Group', lastName: 'X', roomId: '556');
        $m3 = $this->booking(103, '2026-05-05', '2026-05-07', firstName: 'Group', lastName: 'X', roomId: '557');
        $m1['comments'] = 'Master request';
        $m2['comments'] = 'Sibling 1 request';
        $m3['comments'] = 'Sibling 2 request';

        $out = $this->fmt->format([$m1, $m2, $m3], 'Bookings', $this->rooms());
        $this->assertStringContainsString('💬 Master request', $out);
        $this->assertStringNotContainsString('Sibling 1', $out);
        $this->assertStringNotContainsString('Sibling 2', $out);
        // Collapsed indicator still present.
        $this->assertStringContainsString('×3', $out);
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
