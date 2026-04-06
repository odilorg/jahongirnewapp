<?php

namespace Tests\Unit;

use App\DTO\PaymentPresentation;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Covers PaymentPresentation::fromArray() key-validation fix.
 *
 * Bug: before the fix, missing session keys caused a PHP undefined-index warning
 * and then a TypeError when a typed constructor parameter received null.
 * The TypeError was not a RuntimeException so some callers did not catch it,
 * leading to a 500 instead of the "FX unavailable" operator message.
 *
 * After the fix: explicit InvalidArgumentException with a descriptive message.
 */
class PaymentPresentationFromArrayTest extends TestCase
{
    private function validArray(): array
    {
        return [
            'beds24_booking_id' => 'B_TEST_001',
            'sync_id'           => 42,
            'daily_rate_id'     => 7,
            'guest_name'        => 'Jane Smith',
            'arrival_date'      => '2026-04-10',
            'uzs_presented'     => 1_280_000,
            'eur_presented'     => 92,
            'rub_presented'     => 9200,
            'fx_rate_date'      => '06.04.2026',
            'bot_session_id'    => 'sess-abc',
            'presented_at'      => '2026-04-06T10:00:00+05:00',
        ];
    }

    // ── Happy path ────────────────────────────────────────────────────────────

    /** @test */
    public function from_array_constructs_correctly_with_all_required_keys(): void
    {
        $p = PaymentPresentation::fromArray($this->validArray());

        $this->assertEquals('B_TEST_001', $p->beds24BookingId);
        $this->assertEquals(42, $p->syncId);
        $this->assertEquals(7, $p->dailyExchangeRateId);
        $this->assertEquals('Jane Smith', $p->guestName);
        $this->assertEquals(1_280_000, $p->uzsPresented);
    }

    /** @test */
    public function from_array_allows_null_daily_rate_id(): void
    {
        $data = $this->validArray();
        unset($data['daily_rate_id']); // optional — null means no daily rate linked

        $p = PaymentPresentation::fromArray($data);

        $this->assertNull($p->dailyExchangeRateId);
    }

    /** @test */
    public function to_array_round_trips_through_from_array(): void
    {
        $original = PaymentPresentation::fromArray($this->validArray());
        $rebuilt  = PaymentPresentation::fromArray($original->toArray());

        $this->assertEquals($original->beds24BookingId, $rebuilt->beds24BookingId);
        $this->assertEquals($original->syncId, $rebuilt->syncId);
        $this->assertEquals($original->uzsPresented, $rebuilt->uzsPresented);
        $this->assertEquals($original->fxRateDate, $rebuilt->fxRateDate);
    }

    // ── Missing required keys ─────────────────────────────────────────────────

    /**
     * @test
     * @dataProvider missingKeyProvider
     */
    public function from_array_throws_invalid_argument_exception_for_missing_key(string $key): void
    {
        $data = $this->validArray();
        unset($data[$key]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/missing required key: '{$key}'/");

        PaymentPresentation::fromArray($data);
    }

    public static function missingKeyProvider(): array
    {
        return [
            'beds24_booking_id' => ['beds24_booking_id'],
            'sync_id'           => ['sync_id'],
            'guest_name'        => ['guest_name'],
            'arrival_date'      => ['arrival_date'],
            'uzs_presented'     => ['uzs_presented'],
            'eur_presented'     => ['eur_presented'],
            'rub_presented'     => ['rub_presented'],
            'fx_rate_date'      => ['fx_rate_date'],
            'bot_session_id'    => ['bot_session_id'],
            'presented_at'      => ['presented_at'],
        ];
    }

    /** @test */
    public function from_array_throws_for_empty_array(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PaymentPresentation::fromArray([]);
    }

    /** @test */
    public function exception_from_missing_key_is_catchable_as_throwable(): void
    {
        // CashierBotController catches \Throwable — verify the exception hierarchy
        $data = $this->validArray();
        unset($data['sync_id']);

        $caught = false;
        try {
            PaymentPresentation::fromArray($data);
        } catch (\Throwable) {
            $caught = true;
        }

        $this->assertTrue($caught);
    }

    // ── isExpired() ───────────────────────────────────────────────────────────

    /** @test */
    public function is_expired_returns_false_for_fresh_presentation(): void
    {
        $data               = $this->validArray();
        $data['presented_at'] = now()->toIso8601String();

        $p = PaymentPresentation::fromArray($data);

        $this->assertFalse($p->isExpired());
    }

    /** @test */
    public function is_expired_returns_true_after_ttl_minutes(): void
    {
        $data               = $this->validArray();
        $data['presented_at'] = now()->subMinutes(PaymentPresentation::TTL_MINUTES + 1)->toIso8601String();

        $p = PaymentPresentation::fromArray($data);

        $this->assertTrue($p->isExpired());
    }
}
