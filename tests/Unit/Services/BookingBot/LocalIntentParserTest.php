<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BookingBot;

use App\Services\BookingBot\LocalIntentParser;
use App\Support\BookingBot\DateRangeParser;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class LocalIntentParserTest extends TestCase
{
    private LocalIntentParser $p;

    protected function setUp(): void
    {
        parent::setUp();
        $this->p = new LocalIntentParser(new DateRangeParser());
    }

    // ── Match / fallthrough ─────────────────────────────────────────────

    /**
     * @dataProvider matchedInputs
     * @param array<string,mixed>|null $expect (null = assert-match, values checked individually)
     */
    public function test_matches_expected_intent(string $input, string $expectedIntent): void
    {
        $out = $this->p->tryParse($input);
        $this->assertNotNull($out, "'{$input}' should match locally");
        $this->assertSame($expectedIntent, $out['intent']);
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function matchedInputs(): iterable
    {
        yield 'cancel short'            => ['cancel 12345',                'cancel_booking'];
        yield 'cancel with word'        => ['cancel booking 12345',        'cancel_booking'];
        yield 'cancel with hash'        => ['cancel #12345',               'cancel_booking'];
        yield 'show detail'             => ['show 12345',                  'show_booking'];
        yield 'details with hash'       => ['details #12345',              'show_booking'];
        yield 'bookings today'          => ['bookings today',              'view_bookings'];
        yield 'today alone'             => ['today',                       'view_bookings'];
        yield 'tomorrow alone'          => ['tomorrow',                    'view_bookings'];
        yield 'arrivals today plain'    => ['arrivals today',              'view_bookings'];
        yield 'departures today plain'  => ['departures today',            'view_bookings'];
        yield 'current alone'           => ['current',                     'view_bookings'];
        yield 'current bookings'        => ['current bookings',            'view_bookings'];
        yield 'in-house'                => ['in-house',                    'view_bookings'];
        yield 'inhouse'                 => ['inhouse',                     'view_bookings'];
        yield 'new bookings'            => ['new bookings',                'view_bookings'];
        yield 'bookings on may 5'       => ['bookings on may 5',           'view_bookings'];
        yield 'bookings may 5'          => ['bookings may 5',              'view_bookings'];
        yield 'bookings may 5-10'       => ['bookings may 5-10',           'view_bookings'];
        yield 'arrivals may 5-10'       => ['arrivals may 5-10',           'view_bookings'];
        yield 'departures may 5'        => ['departures may 5',            'view_bookings'];
        yield 'bookings iso date'       => ['bookings 2030-05-05',         'view_bookings'];
        yield 'search guest'            => ['search john smith',           'view_bookings'];
        yield 'find guest'              => ['find alice',                  'view_bookings'];
        // ── check_availability (regex, was LLM-only before 2026-05-07)
        yield 'avail dash range end'    => ['9-10 may avail',              'check_availability'];
        yield 'avail dash range 2 ngts' => ['9-11 may avail',              'check_availability'];
        yield 'avail today end'         => ['today avail',                 'check_availability'];
        yield 'avail tomorrow end'      => ['tomorrow avail',              'check_availability'];
        yield 'availability long word'  => ['21-28 may availability',      'check_availability'];
        yield 'avail keyword first'     => ['avail 9-10 may',              'check_availability'];
        yield 'availability first'      => ['availability 21-28 may',      'check_availability'];
        yield 'avail today (prefix)'    => ['avail today',                 'check_availability'];
        yield 'availability tomorrow p' => ['availability tomorrow',       'check_availability'];
    }

    /** @dataProvider fuzzyInputs */
    public function test_returns_null_for_ambiguous_input(string $input): void
    {
        $this->assertNull(
            $this->p->tryParse($input),
            "'{$input}' should fall through to LLM"
        );
    }

    /** @return iterable<string, array{0: string}> */
    public static function fuzzyInputs(): iterable
    {
        yield 'create booking'       => ['book room 12 under john jan 2-3 tel +1234567890'];
        yield 'create group booking' => ['book rooms 12 and 14 under john walker jan 5-7 tel +123'];
        yield 'modify booking'       => ['modify booking 12345 to jan 5-7'];
        yield 'check availability'   => ['check avail jan 5-7'];
        yield 'empty'                => [''];
        yield 'gibberish'            => ['asdf asdf asdf'];
        yield 'partial cancel'       => ['cancel'];
        yield 'bookings maybe 5'     => ['bookings maybe 5'];
        yield 'bad date'             => ['bookings nothing'];
        yield 'random text'          => ['hi how are you'];
    }

    // ── Semantic correctness ────────────────────────────────────────────

    public function test_cancel_extracts_booking_id(): void
    {
        $this->assertSame(['intent' => 'cancel_booking', 'booking_id' => '85700123'],
            $this->p->tryParse('cancel booking 85700123'));
    }

    public function test_show_detail_extracts_booking_id(): void
    {
        $this->assertSame(['intent' => 'show_booking', 'booking_id' => '85700123'],
            $this->p->tryParse('show #85700123'));
    }

    public function test_bookings_today_emits_filter_type_and_today_dates(): void
    {
        $out = $this->p->tryParse('bookings today');
        $today = CarbonImmutable::now()->format('Y-m-d');
        $this->assertSame('today', $out['filter_type']);
        $this->assertSame($today, $out['dates']['check_in']);
        $this->assertSame($today, $out['dates']['check_out']);
    }

    public function test_bookings_range_emits_dates_without_filter_type(): void
    {
        $out = $this->p->tryParse('bookings may 5-10');
        $this->assertArrayNotHasKey('filter_type', $out);
        $this->assertMatchesRegularExpression('/\d{4}-05-05/', $out['dates']['check_in']);
        $this->assertMatchesRegularExpression('/\d{4}-05-10/', $out['dates']['check_out']);
    }

    public function test_arrivals_range_sets_filter_type(): void
    {
        $out = $this->p->tryParse('arrivals may 5-10');
        $this->assertSame('arrivals', $out['filter_type']);
    }

    public function test_departures_range_sets_filter_type(): void
    {
        $out = $this->p->tryParse('departures may 5');
        $this->assertSame('departures', $out['filter_type']);
    }

    public function test_search_captures_query_and_respects_length_cap(): void
    {
        $this->assertSame(
            ['intent' => 'view_bookings', 'search_string' => 'john smith'],
            $this->p->tryParse('search john smith'),
        );
        // Over-long queries (> 60 chars) fall through.
        $this->assertNull($this->p->tryParse('search ' . str_repeat('a', 61)));
    }

    // ── check_availability semantic tests (2026-05-07 regression-proofing)

    public function test_avail_dash_range_extracts_one_night(): void
    {
        $out = $this->p->tryParse('9-10 may avail');
        $this->assertSame('check_availability', $out['intent']);
        $this->assertMatchesRegularExpression('/\d{4}-05-09/', $out['dates']['check_in']);
        $this->assertMatchesRegularExpression('/\d{4}-05-10/', $out['dates']['check_out']);
    }

    public function test_avail_dash_range_extracts_two_nights(): void
    {
        $out = $this->p->tryParse('9-11 may avail');
        $this->assertSame('check_availability', $out['intent']);
        $this->assertMatchesRegularExpression('/\d{4}-05-09/', $out['dates']['check_in']);
        $this->assertMatchesRegularExpression('/\d{4}-05-11/', $out['dates']['check_out']);
    }

    public function test_avail_today_returns_one_night_starting_today(): void
    {
        $out      = $this->p->tryParse('today avail');
        $today    = CarbonImmutable::now()->format('Y-m-d');
        $tomorrow = CarbonImmutable::now()->addDay()->format('Y-m-d');
        $this->assertSame('check_availability', $out['intent']);
        $this->assertSame($today, $out['dates']['check_in']);
        $this->assertSame($tomorrow, $out['dates']['check_out']);
    }

    public function test_avail_tomorrow_returns_one_night_starting_tomorrow(): void
    {
        $out      = $this->p->tryParse('tomorrow avail');
        $tomorrow = CarbonImmutable::now()->addDay()->format('Y-m-d');
        $dayAfter = CarbonImmutable::now()->addDays(2)->format('Y-m-d');
        $this->assertSame('check_availability', $out['intent']);
        $this->assertSame($tomorrow, $out['dates']['check_in']);
        $this->assertSame($dayAfter, $out['dates']['check_out']);
    }

    public function test_avail_keyword_at_start_works(): void
    {
        $out = $this->p->tryParse('avail 9-10 may');
        $this->assertSame('check_availability', $out['intent']);
        $this->assertMatchesRegularExpression('/\d{4}-05-09/', $out['dates']['check_in']);
        $this->assertMatchesRegularExpression('/\d{4}-05-10/', $out['dates']['check_out']);
    }

    public function test_availability_long_word_at_start_works(): void
    {
        $out = $this->p->tryParse('availability 21-28 may');
        $this->assertSame('check_availability', $out['intent']);
        $this->assertMatchesRegularExpression('/\d{4}-05-21/', $out['dates']['check_in']);
        $this->assertMatchesRegularExpression('/\d{4}-05-28/', $out['dates']['check_out']);
    }

    public function test_avail_single_named_date_auto_extends_to_one_night(): void
    {
        // "5 may avail" → DateRangeParser collapses to check_in == check_out;
        // matchAvailRange must advance check_out by 1 day so Beds24 sees a
        // 1-night stay rather than 0 nights.
        $out = $this->p->tryParse('5 may avail');
        $this->assertSame('check_availability', $out['intent']);
        $this->assertMatchesRegularExpression('/\d{4}-05-05/', $out['dates']['check_in']);
        $this->assertMatchesRegularExpression('/\d{4}-05-06/', $out['dates']['check_out']);
    }

    public function test_avail_alone_falls_through(): void
    {
        // No date portion → cannot resolve; must return null so the
        // coordinator falls through to the LLM where the human-language
        // grammar might still rescue it.
        $this->assertNull($this->p->tryParse('avail'));
        $this->assertNull($this->p->tryParse('availability'));
    }

    public function test_avail_with_unparseable_rest_falls_through(): void
    {
        // "avail nonsense" — DateRangeParser returns null → must fall
        // through, not silently produce check_availability with no dates.
        $this->assertNull($this->p->tryParse('avail nonsense'));
        $this->assertNull($this->p->tryParse('asdf avail'));
    }

    public function test_avail_iso_date_works(): void
    {
        // ISO single-date input. DateRangeParser already handles ISO;
        // matchAvailRange must auto-extend check_out by 1 day for the
        // 1-night-stay convention.
        $out = $this->p->tryParse('2026-05-09 avail');
        $this->assertSame('check_availability', $out['intent']);
        $this->assertSame('2026-05-09', $out['dates']['check_in']);
        $this->assertSame('2026-05-10', $out['dates']['check_out']);
    }

    public function test_avail_iso_range_works(): void
    {
        $out = $this->p->tryParse('2026-05-09 to 2026-05-12 avail');
        $this->assertSame('check_availability', $out['intent']);
        $this->assertSame('2026-05-09', $out['dates']['check_in']);
        $this->assertSame('2026-05-12', $out['dates']['check_out']);
    }

    public function test_trailing_avail_does_not_silently_become_check_availability(): void
    {
        // Defensive: the "<rest> avail" pattern is a permissive
        // .+? match; the only thing stopping `cancel booking #5 avail`
        // from masquerading as an availability query is DateRangeParser
        // returning null for `cancel booking #5`. Pin that contract so a
        // future DateRangeParser change can't silently re-route bookings
        // commands to avail.
        //
        // We assert intent ≠ check_availability rather than null, because
        // some inputs (e.g. `search guest john avail`) legitimately
        // resolve as another local intent (matchSearch picks them up
        // after matchAvailRange falls through). The safety property is
        // "doesn't masquerade as avail", not "doesn't match anything".
        $cancelOut = $this->p->tryParse('cancel booking #5 avail');
        $this->assertTrue(
            $cancelOut === null || ($cancelOut['intent'] ?? null) !== 'check_availability',
            '`cancel booking #5 avail` must not be routed to check_availability'
        );

        $searchOut = $this->p->tryParse('search guest john avail');
        $this->assertNotSame('check_availability', $searchOut['intent'] ?? null,
            '`search guest john avail` must not be routed to check_availability');
    }
}
