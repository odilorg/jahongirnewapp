<?php

namespace Tests\Feature\Fx;

use App\DTOs\Fx\OverrideEvaluation;
use App\Enums\Currency;
use App\Enums\OverrideTier;
use App\Services\Fx\OverridePolicyEvaluator;
use Tests\TestCase;

/**
 * Regression tests for BUG-03:
 * The active override evaluator must return OverrideTier::Blocked for extreme variance.
 * The old App\Services\OverridePolicyEvaluator never returned Blocked.
 */
class OverridePolicyEvaluatorControllerTest extends TestCase
{
    private OverridePolicyEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        // Use the Fx version — the one now injected into CashierBotController
        $this->evaluator = app(OverridePolicyEvaluator::class);
    }

    /** @test */
    public function exact_amount_returns_none_tier(): void
    {
        $result = $this->evaluator->evaluate(Currency::UZS, 1_000_000, 1_000_000);

        $this->assertEquals(OverrideTier::None, $result->tier);
        $this->assertTrue($result->withinTolerance);
    }

    /** @test */
    public function tiny_variance_within_tolerance_returns_none(): void
    {
        // 0.3% variance — within tolerance_pct of 0.5%
        $result = $this->evaluator->evaluate(Currency::UZS, 1_000_000, 1_003_000);

        $this->assertEquals(OverrideTier::None, $result->tier);
        $this->assertTrue($result->withinTolerance);
    }

    /** @test */
    public function small_variance_returns_cashier_tier(): void
    {
        // ~1.5% variance — above tolerance (0.5%) but below cashier threshold (2%)
        $result = $this->evaluator->evaluate(Currency::UZS, 1_000_000, 985_000);

        $this->assertEquals(OverrideTier::Cashier, $result->tier);
        $this->assertFalse($result->withinTolerance);
    }

    /** @test */
    public function medium_variance_returns_manager_tier(): void
    {
        // ~5% variance — above cashier (2%) but below manager threshold (10%)
        $result = $this->evaluator->evaluate(Currency::UZS, 1_000_000, 950_000);

        $this->assertEquals(OverrideTier::Manager, $result->tier);
    }

    /** @test */
    public function extreme_variance_returns_blocked_tier(): void
    {
        // 50% variance — well above manager threshold (10%)
        $result = $this->evaluator->evaluate(Currency::UZS, 1_000_000, 500_000);

        $this->assertEquals(OverrideTier::Blocked, $result->tier,
            'Extreme variance must return Blocked — old evaluator never returned this tier');
        $this->assertTrue($result->isBlocked());
        $this->assertFalse($result->canProceed());
    }

    /** @test */
    public function blocked_tier_means_payment_cannot_proceed(): void
    {
        $result = $this->evaluator->evaluate(Currency::USD, 100, 1_000);

        $this->assertEquals(OverrideTier::Blocked, $result->tier);
        $this->assertFalse($result->canProceed());
    }

    /** @test */
    public function result_is_override_evaluation_dto(): void
    {
        $result = $this->evaluator->evaluate(Currency::EUR, 500, 510);

        $this->assertInstanceOf(OverrideEvaluation::class, $result);
        $this->assertEquals(Currency::EUR, $result->currency);
        $this->assertEquals(500.0, $result->presentedAmount);
        $this->assertEquals(510.0, $result->proposedAmount);
        $this->assertGreaterThan(0, $result->variancePct);
    }

    /** @test */
    public function variance_pct_is_calculated_correctly(): void
    {
        // 10% over-payment
        $result = $this->evaluator->evaluate(Currency::UZS, 100_000, 110_000);

        $this->assertEqualsWithDelta(10.0, $result->variancePct, 0.01);
    }

    /** @test */
    public function zero_presented_amount_returns_none_tier(): void
    {
        // Guard against division by zero
        $result = $this->evaluator->evaluate(Currency::UZS, 0, 100_000);

        $this->assertEquals(OverrideTier::None, $result->tier);
    }
}
