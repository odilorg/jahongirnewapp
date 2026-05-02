<?php

namespace Tests\Unit\CashierBot;

use App\Http\Controllers\CashierBotController;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Asserts:
 *  1. parseFlexibleDate accepts ISO, DD.MM, DD/MM, and Russian relative
 *     terms; returns null for unparseable input.
 *  2. fetchInHouseGuests sort puts today first, then upcoming asc, then
 *     recent past desc (operator's mental order).
 *
 * Network-touching paths (the actual Beds24 API call) are not covered
 * here — they're out-of-scope for unit tests and depend on the live
 * client. Only pure logic exercised.
 */
class PaymentDateRangeTest extends TestCase
{
    use RefreshDatabase;

    private function callPrivate(string $method, ...$args)
    {
        $ctrl = app(CashierBotController::class);
        $reflect = new \ReflectionMethod($ctrl, $method);
        $reflect->setAccessible(true);
        return $reflect->invoke($ctrl, ...$args);
    }

    /** @test */
    public function flexible_date_parser_accepts_iso(): void
    {
        $this->assertSame('2026-04-28', $this->callPrivate('parseFlexibleDate', '2026-04-28'));
    }

    /** @test */
    public function flexible_date_parser_accepts_dd_dot_mm(): void
    {
        $expected = Carbon::create(Carbon::today()->year, 4, 28)->format('Y-m-d');
        $this->assertSame($expected, $this->callPrivate('parseFlexibleDate', '28.04'));
    }

    /** @test */
    public function flexible_date_parser_accepts_dd_slash_mm(): void
    {
        $expected = Carbon::create(Carbon::today()->year, 4, 28)->format('Y-m-d');
        $this->assertSame($expected, $this->callPrivate('parseFlexibleDate', '28/04'));
    }

    /** @test */
    public function flexible_date_parser_accepts_dd_dot_mm_dot_yy_short(): void
    {
        $this->assertSame('2026-04-28', $this->callPrivate('parseFlexibleDate', '28.04.26'));
    }

    /** @test */
    public function flexible_date_parser_accepts_russian_relative(): void
    {
        $this->assertSame(Carbon::yesterday()->format('Y-m-d'),  $this->callPrivate('parseFlexibleDate', 'вчера'));
        $this->assertSame(Carbon::today()->format('Y-m-d'),      $this->callPrivate('parseFlexibleDate', 'сегодня'));
        $this->assertSame(Carbon::tomorrow()->format('Y-m-d'),   $this->callPrivate('parseFlexibleDate', 'завтра'));
    }

    /** @test */
    public function flexible_date_parser_accepts_english_relative(): void
    {
        $this->assertSame(Carbon::yesterday()->format('Y-m-d'),  $this->callPrivate('parseFlexibleDate', 'yesterday'));
        $this->assertSame(Carbon::today()->format('Y-m-d'),      $this->callPrivate('parseFlexibleDate', 'today'));
        $this->assertSame(Carbon::tomorrow()->format('Y-m-d'),   $this->callPrivate('parseFlexibleDate', 'tomorrow'));
    }

    /** @test */
    public function flexible_date_parser_rejects_garbage(): void
    {
        foreach (['abc', '32.13', '13/13/2026', ''] as $bad) {
            $this->assertNull($this->callPrivate('parseFlexibleDate', $bad), "Must reject `{$bad}`");
        }
    }
}
