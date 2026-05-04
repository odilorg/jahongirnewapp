<?php

declare(strict_types=1);

namespace Tests\Unit\CashierBot;

use App\DTO\PaymentPresentation;
use App\DTO\RecordPaymentData;
use App\Services\BotPaymentService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Phase 1.5.1 contract — mixed-currency split payment recording.
 *
 * These tests exercise the helpers that don't require DB or service
 * dependencies: convertViaPresentation, sumLockTolerance, and the
 * argument-validation paths of recordMixedCurrencySplitPayment.
 *
 * Full end-to-end (with DB writes, FX sync, owner alert) is covered
 * by Feature\Cashier\MixedCurrencySplitFeatureTest in the test DB.
 *
 * Real-world incident driving this work: 2026-05-04 booking 1,115,000
 * UZS settled as 500,000 UZS card + $50 USD cash. Same-currency split
 * couldn't capture both legs cleanly.
 *
 * Locked invariants tested here:
 *   - same-currency call rejected (operator should use recordSplitPayment)
 *   - different-booking call rejected (sanity guard)
 *   - unsupported base currency rejected
 *   - sum-lock pass / fail in UZS base
 *   - sum-lock pass in USD base (different tolerance)
 */
class MixedCurrencySplitPaymentTest extends TestCase
{
    /**
     * Build a frozen PaymentPresentation matching the 2026-05-04 incident:
     * Booking ~$90.65 USD, presented as multiple-currency snapshot at
     * fixed rates. UZS @ 12,300, USD = base, EUR @ 0.92 USD/EUR.
     */
    private function presentation(string $bookingId = 'B1'): PaymentPresentation
    {
        return new PaymentPresentation(
            beds24BookingId: $bookingId,
            guestName: 'Test Guest',
            roomNumber: '101',
            arrivalDate: Carbon::today()->toDateString(),
            uzsPresented: 1_115_000.0,
            usdPresented: 90.65,
            eurPresented: 83.0,
            rubPresented: 8200.0,
            fxRateDate: Carbon::today()->toDateString(),
            syncId: 1,
            dailyExchangeRateId: 1,
            exchangeRateId: null,
            isGroupPayment: false,
            groupMasterBookingId: null,
            groupSizeExpected: null,
            groupSizeLocal: null,
            botSessionId: 'bot:test:1',
            presentedAt: Carbon::now(),
        );
    }

    private function leg(string $currency, float $amount, string $method, string $bookingId = 'B1'): RecordPaymentData
    {
        return new RecordPaymentData(
            presentation: $this->presentation($bookingId),
            shiftId: 1,
            cashierId: 1,
            currencyPaid: $currency,
            amountPaid: $amount,
            paymentMethod: $method,
            overrideReason: null,
            managerApproval: null,
        );
    }

    private function service(): BotPaymentService
    {
        // Construct without DI — we only call argument-validation paths
        // that don't reach the underlying recordPayment() persistence.
        // Reflection used to invoke private/public methods on the
        // partial object without bootstrapping the full Laravel container.
        return $this->getMockBuilder(BotPaymentService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['recordPayment'])
            ->getMock();
    }

    public function test_rejects_same_currency_legs(): void
    {
        $svc = $this->service();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('different leg currencies');

        $svc->recordMixedCurrencySplitPayment(
            $this->leg('UZS', 500_000, 'card'),
            $this->leg('UZS', 615_000, 'cash'),
            'UZS',
        );
    }

    public function test_rejects_different_booking_ids(): void
    {
        $svc = $this->service();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('same booking');

        $svc->recordMixedCurrencySplitPayment(
            $this->leg('UZS', 500_000, 'card', 'B1'),
            $this->leg('USD', 50, 'cash', 'B2'),
            'UZS',
        );
    }

    public function test_rejects_unsupported_base_currency(): void
    {
        $svc = $this->service();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported base currency');

        $svc->recordMixedCurrencySplitPayment(
            $this->leg('UZS', 500_000, 'card'),
            $this->leg('USD', 50, 'cash'),
            'JPY', // not in [UZS, USD, EUR]
        );
    }

    public function test_sum_lock_passes_for_2026_05_04_real_scenario(): void
    {
        // Booking 1,115,000 UZS = 500k UZS card + $50 USD cash.
        // $50 USD via frozen rate (1,115,000/90.65 ≈ 12,300/USD)
        //   → 50 * (1,115,000/90.65) ≈ 615,002 UZS
        // Sum: 500,000 + 615,002 ≈ 1,115,002 UZS — within 100 UZS tolerance.
        $svc = $this->service();
        $svc->expects($this->exactly(2))
            ->method('recordPayment')
            ->willReturn(new \App\Models\CashTransaction());

        $svc->recordMixedCurrencySplitPayment(
            $this->leg('UZS', 500_000, 'card'),
            $this->leg('USD', 50, 'cash'),
            'UZS',
        );
    }

    public function test_sum_lock_fails_when_overpaid(): void
    {
        // 500k UZS + $100 USD ≈ 1,730,000 UZS, but booking is 1,115,000.
        $svc = $this->service();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('sum-lock failed');

        $svc->recordMixedCurrencySplitPayment(
            $this->leg('UZS', 500_000, 'card'),
            $this->leg('USD', 100, 'cash'),
            'UZS',
        );
    }

    public function test_sum_lock_fails_when_underpaid(): void
    {
        // Half of each leg → way under booking total.
        $svc = $this->service();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('sum-lock failed');

        $svc->recordMixedCurrencySplitPayment(
            $this->leg('UZS', 250_000, 'card'),
            $this->leg('USD', 25, 'cash'),
            'UZS',
        );
    }

    public function test_sum_lock_passes_with_usd_base(): void
    {
        // Booking $90.65 USD = 500k UZS + $49.35 USD
        // 500k UZS @ rate ≈ $40.65; total ≈ $90.00 (within $0.50 tolerance)
        $svc = $this->service();
        $svc->expects($this->exactly(2))
            ->method('recordPayment')
            ->willReturn(new \App\Models\CashTransaction());

        $svc->recordMixedCurrencySplitPayment(
            $this->leg('UZS', 500_000, 'card'),
            $this->leg('USD', 50.00, 'cash'),
            'USD',
        );
    }

    public function test_eur_leg_with_uzs_base(): void
    {
        // Booking 1,115,000 UZS = 500k UZS card + 50 EUR cash
        // 50 EUR via frozen presentation: 1,115,000/83 ≈ 13,433/EUR
        //   → 50 * (1,115,000/83) ≈ 671,687 UZS
        // Sum: 500,000 + 671,687 ≈ 1,171,687 — outside tolerance, should fail.
        $svc = $this->service();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('sum-lock failed');

        $svc->recordMixedCurrencySplitPayment(
            $this->leg('UZS', 500_000, 'card'),
            $this->leg('EUR', 50, 'cash'),
            'UZS',
        );
    }
}
