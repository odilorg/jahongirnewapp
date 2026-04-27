<?php

declare(strict_types=1);

namespace Tests\Unit\Fx;

use App\Exceptions\Fx\InvalidFxOverrideException;
use App\Services\Fx\FxThresholdGuard;
use Tests\TestCase;

/**
 * Unit tests for the simplified FX policy. Pins the contract from
 * docs/architecture/fx-simplification-plan.md §1:
 *
 *   ≤ 3% silent
 *   3–15% require non-empty override_reason
 *   > 15% reject (InvalidFxOverrideException)
 */
final class FxThresholdGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Lock the thresholds for these tests so changes to .env or
        // config defaults don't quietly break the contract.
        config([
            'cashier.fx.override_reason_required_pct' => 3.0,
            'cashier.fx.hard_block_pct'               => 15.0,
        ]);
    }

    public function test_deviation_pct_is_signed_and_rounded_to_4_decimals(): void
    {
        $guard = new FxThresholdGuard();

        $this->assertEqualsWithDelta(2.0,  $guard->deviationPct(12700.0, 12954.0), 0.0001);
        $this->assertEqualsWithDelta(-1.0, $guard->deviationPct(12700.0, 12573.0), 0.0001);
        $this->assertSame(0.0, $guard->deviationPct(12700.0, 12700.0));
    }

    public function test_deviation_pct_throws_on_zero_reference_rate(): void
    {
        $guard = new FxThresholdGuard();

        $this->expectException(\InvalidArgumentException::class);
        $guard->deviationPct(0.0, 12700.0);
    }

    public function test_exact_match_passes_silently(): void
    {
        $guard = new FxThresholdGuard();
        $guard->validate(0.0, null);
        $this->assertFalse($guard->wasOverridden(0.0));
    }

    public function test_2_percent_deviation_no_reason_passes_silently(): void
    {
        $guard = new FxThresholdGuard();
        $guard->validate(2.0, null);
        $guard->validate(-2.0, null);
        $this->assertTrue($guard->wasOverridden(2.0));
        $this->assertTrue($guard->wasOverridden(-2.0));
    }

    public function test_8_percent_deviation_without_reason_is_rejected(): void
    {
        $guard = new FxThresholdGuard();
        $this->expectException(InvalidFxOverrideException::class);
        $this->expectExceptionMessage('Укажите причину');
        $guard->validate(8.0, null);
    }

    public function test_8_percent_deviation_with_whitespace_reason_is_rejected(): void
    {
        $guard = new FxThresholdGuard();
        $this->expectException(InvalidFxOverrideException::class);
        $guard->validate(8.0, '   ');
    }

    public function test_8_percent_deviation_with_real_reason_passes(): void
    {
        $guard = new FxThresholdGuard();
        $guard->validate(8.0, 'Гость дал больше — сдача в долларах не было.');
        $guard->validate(-8.0, 'Согласовано на ресепшене.');
        $this->assertTrue(true); // no throw
    }

    public function test_20_percent_deviation_is_hard_blocked_even_with_reason(): void
    {
        $guard = new FxThresholdGuard();
        $this->expectException(InvalidFxOverrideException::class);
        $this->expectExceptionMessage('максимально допустимое отклонение');
        $guard->validate(20.0, 'Гость настаивал.');
    }

    public function test_negative_20_percent_deviation_is_hard_blocked(): void
    {
        $guard = new FxThresholdGuard();
        $this->expectException(InvalidFxOverrideException::class);
        $guard->validate(-20.0, 'reason');
    }

    public function test_threshold_at_exact_boundary_3_percent_is_silent(): void
    {
        // 3.0 is INSIDE the silent band (≤ 3.0).
        $guard = new FxThresholdGuard();
        $guard->validate(3.0, null);
        $guard->validate(-3.0, null);
        $this->assertTrue(true);
    }

    public function test_threshold_at_exact_boundary_15_percent_requires_reason_not_blocked(): void
    {
        // 15.0 is INSIDE the require-reason band (≤ 15.0), not blocked.
        $guard = new FxThresholdGuard();
        $guard->validate(15.0, 'edge case');
        $this->assertTrue(true);
    }

    public function test_thresholds_can_be_widened_via_config(): void
    {
        config([
            'cashier.fx.override_reason_required_pct' => 5.0,
            'cashier.fx.hard_block_pct'               => 25.0,
        ]);

        $guard = new FxThresholdGuard();
        $guard->validate(4.5, null);    // would have failed under 3% default
        $guard->validate(20.0, 'r');    // would have been blocked under 15% default
        $this->assertTrue(true);
    }
}
