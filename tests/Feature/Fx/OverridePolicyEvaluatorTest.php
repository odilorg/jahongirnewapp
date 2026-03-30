<?php

namespace Tests\Feature\Fx;

use App\Enums\Currency;
use App\Enums\OverrideTier;
use App\Services\Fx\OverridePolicyEvaluator;
use Tests\TestCase;

class OverridePolicyEvaluatorTest extends TestCase
{
    private OverridePolicyEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();

        // Set known config values for deterministic tests
        config([
            'fx.tolerance_pct'        => 0.5,
            'fx.cashier_threshold_pct' => 2.0,
            'fx.manager_threshold_pct' => 10.0,
        ]);

        $this->evaluator = new OverridePolicyEvaluator();
    }

    /** @test */
    public function exact_match_is_within_tolerance_tier_none(): void
    {
        $result = $this->evaluator->evaluate(Currency::UZS, 1_000_000, 1_000_000);

        $this->assertTrue($result->withinTolerance);
        $this->assertSame(OverrideTier::None, $result->tier);
        $this->assertSame(0.0, $result->variancePct);
        $this->assertFalse($result->isBlocked());
        $this->assertFalse($result->requiresApproval());
    }

    /** @test */
    public function variance_within_0_5_pct_is_auto_accepted(): void
    {
        // 0.4% variance — within 0.5% tolerance
        $result = $this->evaluator->evaluate(Currency::UZS, 1_000_000, 996_000);

        $this->assertTrue($result->withinTolerance);
        $this->assertSame(OverrideTier::None, $result->tier);
    }

    /** @test */
    public function variance_just_above_tolerance_requires_cashier_tier(): void
    {
        // 1% variance — above 0.5% tolerance but below 2% cashier threshold
        $result = $this->evaluator->evaluate(Currency::USD, 100.0, 99.0);

        $this->assertFalse($result->withinTolerance);
        $this->assertSame(OverrideTier::Cashier, $result->tier);
        $this->assertTrue($result->canProceed());
        $this->assertFalse($result->requiresApproval());
    }

    /** @test */
    public function variance_above_cashier_threshold_requires_manager_tier(): void
    {
        // 5% variance — above 2% cashier threshold but below 10% manager threshold
        $result = $this->evaluator->evaluate(Currency::EUR, 100.0, 95.0);

        $this->assertFalse($result->withinTolerance);
        $this->assertSame(OverrideTier::Manager, $result->tier);
        $this->assertTrue($result->requiresApproval());
        $this->assertFalse($result->isBlocked());
    }

    /** @test */
    public function variance_above_manager_threshold_is_blocked(): void
    {
        // 15% variance — above 10% manager threshold
        $result = $this->evaluator->evaluate(Currency::RUB, 10_000.0, 8_500.0);

        $this->assertSame(OverrideTier::Blocked, $result->tier);
        $this->assertTrue($result->isBlocked());
        $this->assertFalse($result->canProceed());
    }

    /** @test */
    public function overpayment_is_evaluated_symmetrically(): void
    {
        // 5% overpayment — also goes through the same tiers
        $result = $this->evaluator->evaluate(Currency::USD, 100.0, 105.0);

        $this->assertSame(OverrideTier::Manager, $result->tier);
        $this->assertEqualsWithDelta(5.0, $result->variancePct, 0.01);
    }

    /** @test */
    public function zero_presented_amount_does_not_divide_by_zero(): void
    {
        $result = $this->evaluator->evaluate(Currency::USD, 0.0, 50.0);

        $this->assertSame(0.0, $result->variancePct);
        $this->assertTrue($result->withinTolerance);
    }
}
