<?php

declare(strict_types=1);

namespace Tests\Feature\Fx;

use App\Exceptions\Fx\InvalidFxOverrideException;
use App\Models\CashTransaction;
use App\Services\BotPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 1: recordPaymentSimple() is the future entry point but is
 * NOT wired to any caller. These tests exercise it directly so the
 * regression bedding for Phase 2 is in place before the cashier flow
 * flips.
 */
final class RecordPaymentSimpleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cashier.fx.override_reason_required_pct' => 3.0,
            'cashier.fx.hard_block_pct'               => 15.0,
        ]);
    }

    public function test_silent_payment_writes_zero_deviation_and_no_override(): void
    {
        $tx = $this->bot()->recordPaymentSimple(
            shiftId: $this->fakeShiftId(),
            beds24BookingId: 'B-001',
            amountPaid: 1_270_000,
            currencyPaid: 'UZS',
            paymentMethod: 'cash',
            cashierId: null,
            referenceRate: 12700.0,
            actualRate: 12700.0,
        );

        $this->assertSame(0.0, (float) $tx->deviation_pct);
        $this->assertFalse((bool) $tx->was_overridden);
        $this->assertNull($tx->override_reason);
        $this->assertEquals(12700.0, (float) $tx->reference_rate);
        $this->assertEquals(12700.0, (float) $tx->actual_rate);
    }

    public function test_2_percent_override_records_with_was_overridden_true_no_reason_required(): void
    {
        $tx = $this->bot()->recordPaymentSimple(
            shiftId: $this->fakeShiftId(),
            beds24BookingId: 'B-002',
            amountPaid: 1_295_400,
            currencyPaid: 'UZS',
            paymentMethod: 'cash',
            cashierId: null,
            referenceRate: 12700.0,
            actualRate: 12954.0,
        );

        $this->assertTrue((bool) $tx->was_overridden);
        $this->assertEqualsWithDelta(2.0, (float) $tx->deviation_pct, 0.0001);
        $this->assertNull($tx->override_reason);
    }

    public function test_8_percent_override_without_reason_throws(): void
    {
        $this->expectException(InvalidFxOverrideException::class);
        $this->bot()->recordPaymentSimple(
            shiftId: $this->fakeShiftId(),
            beds24BookingId: 'B-003',
            amountPaid: 1_371_600,
            currencyPaid: 'UZS',
            paymentMethod: 'cash',
            cashierId: null,
            referenceRate: 12700.0,
            actualRate: 13716.0,
        );
        $this->assertSame(0, CashTransaction::count(), 'no row written when validation fails');
    }

    public function test_8_percent_override_with_reason_records_with_reason(): void
    {
        $tx = $this->bot()->recordPaymentSimple(
            shiftId: $this->fakeShiftId(),
            beds24BookingId: 'B-004',
            amountPaid: 1_371_600,
            currencyPaid: 'UZS',
            paymentMethod: 'cash',
            cashierId: null,
            referenceRate: 12700.0,
            actualRate: 13716.0,
            overrideReason: 'Гость согласился на повышенный курс — нет сдачи в долларах',
        );

        $this->assertTrue((bool) $tx->was_overridden);
        $this->assertEqualsWithDelta(8.0, (float) $tx->deviation_pct, 0.0001);
        $this->assertStringContainsString('Гость', (string) $tx->override_reason);
    }

    public function test_20_percent_override_is_blocked_even_with_reason(): void
    {
        $this->expectException(InvalidFxOverrideException::class);
        $this->bot()->recordPaymentSimple(
            shiftId: $this->fakeShiftId(),
            beds24BookingId: 'B-005',
            amountPaid: 1_524_000,
            currencyPaid: 'UZS',
            paymentMethod: 'cash',
            cashierId: null,
            referenceRate: 12700.0,
            actualRate: 15240.0,
            overrideReason: 'Гость настаивал',
        );
    }

    public function test_currency_is_uppercased_for_consistency(): void
    {
        $tx = $this->bot()->recordPaymentSimple(
            shiftId: $this->fakeShiftId(),
            beds24BookingId: 'B-006',
            amountPaid: 1_270_000,
            currencyPaid: 'uzs',
            paymentMethod: 'cash',
            cashierId: null,
            referenceRate: 12700.0,
            actualRate: 12700.0,
        );
        $this->assertSame('UZS', $tx->currency instanceof \BackedEnum ? $tx->currency->value : (string) $tx->currency);
    }

    private function bot(): BotPaymentService
    {
        return app(BotPaymentService::class);
    }

    /**
     * Phase 1 doesn't actually need a real shift row (cashier_shift_id
     * is nullable per the 2026-03-10 migration), so the feature test
     * skips the FK chain and just uses null. If cashier_shift_id ever
     * becomes non-nullable again, switch to a CashierShift::factory()
     * call here.
     */
    private function fakeShiftId(): ?int
    {
        return null;
    }
}
