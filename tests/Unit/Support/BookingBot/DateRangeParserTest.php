<?php

declare(strict_types=1);

namespace Tests\Unit\Support\BookingBot;

use App\Support\BookingBot\DateRangeParser;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class DateRangeParserTest extends TestCase
{
    private DateRangeParser $p;

    protected function setUp(): void
    {
        parent::setUp();
        $this->p = new DateRangeParser();
    }

    public function test_iso_single_date(): void
    {
        $this->assertSame(
            ['check_in' => '2030-05-05', 'check_out' => '2030-05-05'],
            $this->p->parse('2030-05-05'),
        );
    }

    public function test_iso_range(): void
    {
        $this->assertSame(
            ['check_in' => '2030-05-05', 'check_out' => '2030-05-10'],
            $this->p->parse('2030-05-05 to 2030-05-10'),
        );
    }

    public function test_named_single_with_year(): void
    {
        $this->assertSame(
            ['check_in' => '2030-05-05', 'check_out' => '2030-05-05'],
            $this->p->parse('may 5 2030'),
        );
    }

    public function test_named_range_day_first(): void
    {
        $out = $this->p->parse('5-10 may');
        $this->assertNotNull($out);
        $this->assertMatchesRegularExpression('/\d{4}-05-05/', $out['check_in']);
        $this->assertMatchesRegularExpression('/\d{4}-05-10/', $out['check_out']);
    }

    public function test_named_range_with_month_repeated(): void
    {
        $out = $this->p->parse('may 5 - may 10');
        $this->assertNotNull($out);
        $this->assertMatchesRegularExpression('/\d{4}-05-05/', $out['check_in']);
        $this->assertMatchesRegularExpression('/\d{4}-05-10/', $out['check_out']);
    }

    public function test_day_plus_month_dash_trailing_day(): void
    {
        $out = $this->p->parse('5 may - 10');
        $this->assertNotNull($out);
        $this->assertMatchesRegularExpression('/\d{4}-05-05/', $out['check_in']);
        $this->assertMatchesRegularExpression('/\d{4}-05-10/', $out['check_out']);
    }

    public function test_month_without_year_resolves_to_next_occurrence_if_past(): void
    {
        $today = CarbonImmutable::today();
        // Pick a month name guaranteed to be "in the past this year" when
        // today is, say, mid-April — use January.
        $out = $this->p->parseHumanDate('january 5');
        $this->assertNotNull($out);
        $parsed = CarbonImmutable::parse($out);
        $this->assertGreaterThanOrEqual($today, $parsed);
    }

    public function test_bare_digits_without_month_rejected(): void
    {
        $this->assertNull($this->p->parseHumanDate('5'));
        $this->assertNull($this->p->parse('5'));
    }

    public function test_gibberish_rejected(): void
    {
        $this->assertNull($this->p->parse('nothing'));
        $this->assertNull($this->p->parse('maybe 5'));
    }

    public function test_day_first_reversed_rejected(): void
    {
        $this->assertNull($this->p->parse('10-5 may'));
    }
}
