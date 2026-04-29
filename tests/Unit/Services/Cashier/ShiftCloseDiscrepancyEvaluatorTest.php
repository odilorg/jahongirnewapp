<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Cashier;

use App\Enums\OverrideTier;
use App\Services\Cashier\ShiftCloseDiscrepancyEvaluator;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Unit tests for the C1.1 shift-close discrepancy classifier.
 *
 * Pure logic — no DB, no migrations. FX rates are injected via a closure
 * resolver so tests don't touch DailyExchangeRate.
 *
 * Threshold model under test:
 *   severity == 0                 → None
 *   0 < x ≤ reason (100k UZS)     → Cashier
 *   reason < x ≤ manager (1M UZS) → Manager
 *   x > manager                   → Blocked
 *
 * Stale FX bumps to at least Manager (never downgrades Blocked or None).
 */
final class ShiftCloseDiscrepancyEvaluatorTest extends TestCase
{
    /**
     * Default fresh-rate resolver: 12,700 UZS/USD, 13,800 UZS/EUR, all dated today.
     */
    private function freshResolver(): \Closure
    {
        return function (string $currency): array {
            return match ($currency) {
                'USD' => ['rate' => 12700.0, 'date' => Carbon::today()],
                'EUR' => ['rate' => 13800.0, 'date' => Carbon::today()],
                default => ['rate' => 1.0, 'date' => Carbon::today()],
            };
        };
    }

    private function staleResolver(int $daysOld = 14): \Closure
    {
        return function (string $currency) use ($daysOld): array {
            return match ($currency) {
                'USD' => ['rate' => 12700.0, 'date' => Carbon::today()->subDays($daysOld)],
                'EUR' => ['rate' => 13800.0, 'date' => Carbon::today()->subDays($daysOld)],
                default => ['rate' => 1.0, 'date' => Carbon::today()->subDays($daysOld)],
            };
        };
    }

    public function test_zero_delta_returns_tier_none(): void
    {
        $eval = (new ShiftCloseDiscrepancyEvaluator($this->freshResolver()))
            ->evaluate(
                ['UZS' => 1_500_000, 'USD' => 100, 'EUR' => 45],
                ['UZS' => 1_500_000, 'USD' => 100, 'EUR' => 45],
            );

        $this->assertSame(OverrideTier::None, $eval->tier);
        $this->assertSame(0.0, $eval->severityUzs);
        $this->assertFalse($eval->fxStale);
        $this->assertFalse($eval->requiresReason());
        $this->assertFalse($eval->requiresApproval());
        $this->assertTrue($eval->canProceed());
    }

    public function test_under_reason_threshold_returns_cashier_tier(): void
    {
        $eval = (new ShiftCloseDiscrepancyEvaluator($this->freshResolver()))
            ->evaluate(['UZS' => 1_500_000], ['UZS' => 1_450_000]);

        $this->assertSame(OverrideTier::Cashier, $eval->tier);
        $this->assertSame(50_000.0, $eval->severityUzs);
        $this->assertTrue($eval->requiresReason());
        $this->assertFalse($eval->requiresApproval());
    }

    public function test_at_reason_boundary_returns_cashier(): void
    {
        $eval = (new ShiftCloseDiscrepancyEvaluator($this->freshResolver()))
            ->evaluate(['UZS' => 1_500_000], ['UZS' => 1_400_000]);

        $this->assertSame(OverrideTier::Cashier, $eval->tier, 'Exactly 100k = boundary inclusive of Cashier');
        $this->assertSame(100_000.0, $eval->severityUzs);
    }

    public function test_over_reason_under_manager_returns_manager(): void
    {
        $eval = (new ShiftCloseDiscrepancyEvaluator($this->freshResolver()))
            ->evaluate(['UZS' => 1_500_000], ['UZS' => 1_000_000]);

        $this->assertSame(OverrideTier::Manager, $eval->tier);
        $this->assertSame(500_000.0, $eval->severityUzs);
        $this->assertTrue($eval->requiresApproval());
        $this->assertFalse($eval->isBlocked());
    }

    public function test_at_manager_boundary_returns_manager(): void
    {
        $eval = (new ShiftCloseDiscrepancyEvaluator($this->freshResolver()))
            ->evaluate(['UZS' => 2_000_000], ['UZS' => 1_000_000]);

        $this->assertSame(OverrideTier::Manager, $eval->tier, 'Exactly 1M = boundary inclusive of Manager');
        $this->assertSame(1_000_000.0, $eval->severityUzs);
    }

    public function test_over_manager_returns_blocked(): void
    {
        $eval = (new ShiftCloseDiscrepancyEvaluator($this->freshResolver()))
            ->evaluate(['UZS' => 3_000_000], ['UZS' => 1_000_000]);

        $this->assertSame(OverrideTier::Blocked, $eval->tier);
        $this->assertSame(2_000_000.0, $eval->severityUzs);
        $this->assertTrue($eval->isBlocked());
        $this->assertFalse($eval->canProceed());
    }

    public function test_mixed_currency_severity_sums_absolute_uzs_equivalents(): void
    {
        // Δ UZS = -50k, Δ USD = +10 (× 12,700 = 127k), Δ EUR = -5 (× 13,800 = 69k)
        // Severity = 50,000 + 127,000 + 69,000 = 246,000 → Manager
        $eval = (new ShiftCloseDiscrepancyEvaluator($this->freshResolver()))
            ->evaluate(
                ['UZS' => 1_500_000, 'USD' => 100, 'EUR' => 45],
                ['UZS' => 1_450_000, 'USD' => 110, 'EUR' => 40],
            );

        $this->assertSame(OverrideTier::Manager, $eval->tier);
        $this->assertSame(246_000.0, $eval->severityUzs);
    }

    public function test_offsetting_currencies_do_not_cancel(): void
    {
        // UZS +100k, USD -10 (≈ -127k UZS) — naive cancellation would say -27k → None.
        // We require absolute sums: |+100k| + |-127k| = 227k → Manager.
        $eval = (new ShiftCloseDiscrepancyEvaluator($this->freshResolver()))
            ->evaluate(
                ['UZS' => 1_000_000, 'USD' => 100],
                ['UZS' => 1_100_000, 'USD' =>  90],
            );

        $this->assertSame(OverrideTier::Manager, $eval->tier);
        $this->assertSame(227_000.0, $eval->severityUzs);
    }

    public function test_stale_fx_rate_bumps_to_manager_tier_minimum(): void
    {
        // Δ USD = +1 → 12,700 UZS — would normally be Cashier.
        // Stale rate triggers conservative bump to Manager.
        $eval = (new ShiftCloseDiscrepancyEvaluator($this->staleResolver(daysOld: 14)))
            ->evaluate(['USD' => 100], ['USD' => 101]);

        $this->assertSame(OverrideTier::Manager, $eval->tier);
        $this->assertSame(12_700.0, $eval->severityUzs);
        $this->assertTrue($eval->fxStale);
    }

    public function test_stale_fx_rate_does_not_downgrade_blocked(): void
    {
        // Δ USD = +1000 → 12.7M UZS — Blocked tier; stale FX must NOT downgrade.
        $eval = (new ShiftCloseDiscrepancyEvaluator($this->staleResolver(daysOld: 14)))
            ->evaluate(['USD' => 0], ['USD' => 1000]);

        $this->assertSame(OverrideTier::Blocked, $eval->tier);
        $this->assertTrue($eval->fxStale);
    }

    public function test_stale_fx_rate_does_not_upgrade_none(): void
    {
        // Zero delta + stale rate must remain None — there's no discrepancy to escalate.
        $eval = (new ShiftCloseDiscrepancyEvaluator($this->staleResolver(daysOld: 14)))
            ->evaluate(['UZS' => 1_500_000], ['UZS' => 1_500_000]);

        $this->assertSame(OverrideTier::None, $eval->tier);
        $this->assertSame(0.0, $eval->severityUzs);
    }

    public function test_breakdown_carries_per_currency_detail(): void
    {
        $eval = (new ShiftCloseDiscrepancyEvaluator($this->freshResolver()))
            ->evaluate(
                ['UZS' => 1_500_000, 'USD' => 100],
                ['UZS' => 1_450_000, 'USD' => 110],
            );

        $this->assertArrayHasKey('UZS', $eval->perCurrencyBreakdown);
        $this->assertArrayHasKey('USD', $eval->perCurrencyBreakdown);
        $this->assertSame(-50_000.0, $eval->perCurrencyBreakdown['UZS']['delta']);
        $this->assertSame(50_000.0, $eval->perCurrencyBreakdown['UZS']['uzs_equiv']);
        $this->assertSame(10.0, $eval->perCurrencyBreakdown['USD']['delta']);
        $this->assertSame(127_000.0, $eval->perCurrencyBreakdown['USD']['uzs_equiv']);
    }

    public function test_missing_rate_uses_failsafe_so_delta_cannot_be_silenced(): void
    {
        // Resolver returns no rate — delta in that currency must NOT vanish.
        // Failsafe is huge rate → Blocked tier (severe escalation, intentional).
        $resolver = function (string $currency): array {
            return ['rate' => 0.0, 'date' => null];
        };

        $eval = (new ShiftCloseDiscrepancyEvaluator($resolver))
            ->evaluate(['USD' => 100], ['USD' => 101]);

        $this->assertSame(OverrideTier::Blocked, $eval->tier, 'Missing rate must escalate, never silence');
        $this->assertTrue($eval->fxStale);
    }
}
