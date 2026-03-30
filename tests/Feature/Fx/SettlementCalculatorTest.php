<?php

namespace Tests\Feature\Fx;

use App\Enums\CashTransactionSource;
use App\Models\CashTransaction;
use App\Services\Fx\SettlementCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettlementCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private SettlementCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new SettlementCalculator();
    }

    /** @test */
    public function no_payments_returns_full_remaining(): void
    {
        $result = $this->calculator->remaining('B001', 150.0);

        $this->assertSame(150.0, $result->totalUsd);
        $this->assertSame(0.0,   $result->paidUsd);
        $this->assertSame(150.0, $result->remainingUsd);
        $this->assertFalse($result->isFullyPaid());
    }

    /** @test */
    public function cashier_bot_payment_reduces_remaining(): void
    {
        CashTransaction::factory()->create([
            'beds24_booking_id'  => 'B001',
            'source_trigger'     => CashTransactionSource::CashierBot->value,
            'usd_equivalent_paid' => 60.0,
        ]);

        $result = $this->calculator->remaining('B001', 150.0);

        $this->assertEqualsWithDelta(60.0,  $result->paidUsd,      0.001);
        $this->assertEqualsWithDelta(90.0,  $result->remainingUsd, 0.001);
        $this->assertFalse($result->isFullyPaid());
    }

    /** @test */
    public function beds24_external_row_excluded_from_remaining(): void
    {
        // External row — must not count
        CashTransaction::factory()->create([
            'beds24_booking_id'  => 'B001',
            'source_trigger'     => CashTransactionSource::Beds24External->value,
            'usd_equivalent_paid' => 150.0,
        ]);

        $result = $this->calculator->remaining('B001', 150.0);

        $this->assertSame(0.0,   $result->paidUsd);
        $this->assertSame(150.0, $result->remainingUsd);
    }

    /** @test */
    public function multiple_partial_payments_accumulate(): void
    {
        foreach ([50.0, 60.0, 40.0] as $partial) {
            CashTransaction::factory()->create([
                'beds24_booking_id'  => 'B002',
                'source_trigger'     => CashTransactionSource::CashierBot->value,
                'usd_equivalent_paid' => $partial,
            ]);
        }

        $result = $this->calculator->remaining('B002', 150.0);

        $this->assertEqualsWithDelta(150.0, $result->paidUsd, 0.001);
        $this->assertSame(0.0, $result->remainingUsd);
        $this->assertTrue($result->isFullyPaid());
    }

    /** @test */
    public function overpayment_clamps_remaining_to_zero(): void
    {
        CashTransaction::factory()->create([
            'beds24_booking_id'  => 'B003',
            'source_trigger'     => CashTransactionSource::CashierBot->value,
            'usd_equivalent_paid' => 200.0,  // overpaid
        ]);

        $result = $this->calculator->remaining('B003', 150.0);

        $this->assertSame(0.0, $result->remainingUsd);
        $this->assertTrue($result->isFullyPaid());
    }
}
