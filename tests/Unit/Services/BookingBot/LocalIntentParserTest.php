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
}
