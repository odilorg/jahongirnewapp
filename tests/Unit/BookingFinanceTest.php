<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTOs\BookingFinance;
use PHPUnit\Framework\TestCase;

class BookingFinanceTest extends TestCase
{
    // ── fromParsed construction ────────────────────────────────────────────────

    public function test_returns_null_when_price_key_absent(): void
    {
        $result = BookingFinance::fromParsed(['intent' => 'create_booking']);

        $this->assertNull($result);
    }

    public function test_returns_null_when_price_is_null(): void
    {
        $result = BookingFinance::fromParsed(['price' => null]);

        $this->assertNull($result);
    }

    public function test_constructs_object_when_price_present_and_valid(): void
    {
        $result = BookingFinance::fromParsed(['price' => 150.0]);

        $this->assertInstanceOf(BookingFinance::class, $result);
        $this->assertSame(150.0, $result->quotedTotal);
        $this->assertSame('USD', $result->currency);
    }

    public function test_constructs_object_even_when_price_is_zero_for_explicit_validation(): void
    {
        // Zero must construct an object (not null) so validationError() can surface it.
        $result = BookingFinance::fromParsed(['price' => 0]);

        $this->assertInstanceOf(BookingFinance::class, $result);
    }

    public function test_constructs_object_when_price_is_negative(): void
    {
        $result = BookingFinance::fromParsed(['price' => -50]);

        $this->assertInstanceOf(BookingFinance::class, $result);
    }

    public function test_casts_integer_price_to_float(): void
    {
        $result = BookingFinance::fromParsed(['price' => 100]);

        $this->assertSame(100.0, $result->quotedTotal);
    }

    // ── validationError ───────────────────────────────────────────────────────

    public function test_valid_amount_returns_null(): void
    {
        $finance = new BookingFinance(100.0);

        $this->assertNull($finance->validationError());
    }

    public function test_zero_amount_returns_error(): void
    {
        $finance = new BookingFinance(0.0);

        $this->assertNotNull($finance->validationError());
    }

    public function test_negative_amount_returns_error(): void
    {
        $finance = new BookingFinance(-1.0);

        $this->assertNotNull($finance->validationError());
    }

    public function test_amount_exceeding_max_returns_error(): void
    {
        $finance = new BookingFinance(100_000.0);

        $this->assertNotNull($finance->validationError());
    }

    public function test_amount_at_boundary_is_valid(): void
    {
        $finance = new BookingFinance(99_999.0);

        $this->assertNull($finance->validationError());
    }

    public function test_unsupported_currency_returns_error(): void
    {
        $finance = new BookingFinance(100.0, 'EUR');

        $this->assertNotNull($finance->validationError());
        $this->assertStringContainsString('EUR', $finance->validationError());
    }
}
