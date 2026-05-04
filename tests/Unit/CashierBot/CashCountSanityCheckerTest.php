<?php

declare(strict_types=1);

namespace Tests\Unit\CashierBot;

use App\Services\CashierBot\CashCountSanityChecker;
use PHPUnit\Framework\TestCase;

/**
 * Regression contract for the close-shift sanity guards.
 *
 * Real-world incident 2026-05-04: Aziz typed 608 instead of 608,000 at
 * close. Bot accepted silently. Next shift inherited a 608 UZS opening
 * saldo. The system "saw" the discrepancy (logged it, alerted owner)
 * but treated it as informational rather than transactional risk.
 *
 * Locked invariants:
 *   - detectMissingZeros catches ×10 / ×100 / ×1000 in BOTH directions
 *   - tolerance ±2% absorbs partial-cash drift without false negatives
 *   - non-typo amounts (genuine shortages, real deposits) → null
 *   - severity classifier bands at 5% / 20%
 *   - empty / zero inputs handled safely
 */
class CashCountSanityCheckerTest extends TestCase
{
    private CashCountSanityChecker $c;

    protected function setUp(): void
    {
        parent::setUp();
        $this->c = new CashCountSanityChecker();
    }

    // ── detectMissingZeros — dropped zeros (counted too small) ──

    /** @test */
    public function detects_missing_one_zero(): void
    {
        // typed 6080, meant 60800
        $this->assertSame(60800.0, $this->c->detectMissingZeros(6080, 60800));
    }

    /** @test */
    public function detects_missing_two_zeros(): void
    {
        $this->assertSame(60800.0, $this->c->detectMissingZeros(608, 60800));
    }

    /** @test */
    public function detects_missing_three_zeros_aziz_incident(): void
    {
        // The exact 2026-05-04 incident shape
        $this->assertSame(608000.0, $this->c->detectMissingZeros(608, 608000));
    }

    // ── detectMissingZeros — added zeros (counted too large) ──

    /** @test */
    public function detects_extra_one_zero(): void
    {
        // typed 6080000, meant 608000
        $this->assertSame(608000.0, $this->c->detectMissingZeros(6080000, 608000));
    }

    /** @test */
    public function detects_extra_two_zeros(): void
    {
        $this->assertSame(608000.0, $this->c->detectMissingZeros(60800000, 608000));
    }

    /** @test */
    public function detects_extra_three_zeros(): void
    {
        $this->assertSame(608000.0, $this->c->detectMissingZeros(608000000, 608000));
    }

    // ── detectMissingZeros — non-typo cases ──

    /** @test */
    public function legit_partial_amount_returns_null(): void
    {
        // Real partial shortage, not a 10× typo
        $this->assertNull($this->c->detectMissingZeros(540000, 608000));
    }

    /** @test */
    public function exact_match_returns_null(): void
    {
        $this->assertNull($this->c->detectMissingZeros(608000, 608000));
    }

    /** @test */
    public function within_2pct_tolerance_passes(): void
    {
        // 608 × 1000 = 608,000; expected 605,000 → 0.5% drift, still flagged
        $this->assertSame(608000.0, $this->c->detectMissingZeros(608, 605000));
    }

    /** @test */
    public function beyond_tolerance_returns_null(): void
    {
        // 608 × 1000 = 608,000; expected 700,000 → 13% off, not a typo
        $this->assertNull($this->c->detectMissingZeros(608, 700000));
    }

    /** @test */
    public function zero_inputs_return_null(): void
    {
        $this->assertNull($this->c->detectMissingZeros(0, 0));
        $this->assertNull($this->c->detectMissingZeros(100, 0));
        $this->assertNull($this->c->detectMissingZeros(0, 100));
    }

    // ── classifySeverity ──

    /** @test */
    public function severity_green_when_close_or_match(): void
    {
        $this->assertSame('green', $this->c->classifySeverity(608000, 608000));
        $this->assertSame('green', $this->c->classifySeverity(607000, 608000)); // 0.16%
    }

    /** @test */
    public function severity_yellow_at_5_to_20_percent(): void
    {
        $this->assertSame('yellow', $this->c->classifySeverity(580000, 608000)); // ~4.6% → green
        $this->assertSame('yellow', $this->c->classifySeverity(550000, 608000)); // ~9.5% → yellow
        $this->assertSame('yellow', $this->c->classifySeverity(500000, 608000)); // ~17.7% → yellow
    }

    /** @test */
    public function severity_red_above_20_percent(): void
    {
        // The Aziz incident: 608 vs 608,000 = 99.9% off → red
        $this->assertSame('red', $this->c->classifySeverity(608, 608000));
        // 25% short
        $this->assertSame('red', $this->c->classifySeverity(456000, 608000));
    }

    /** @test */
    public function severity_green_for_zero_zero(): void
    {
        $this->assertSame('green', $this->c->classifySeverity(0, 0));
    }

    // ── worstSeverityAcross ──

    /** @test */
    public function worst_severity_promotes_red(): void
    {
        $rows = [
            'UZS' => ['counted' => 608,    'expected' => 608000],   // red
            'USD' => ['counted' => 40,     'expected' => 40],       // green
            'EUR' => ['counted' => 0,      'expected' => 0],        // green
        ];
        $this->assertSame('red', $this->c->worstSeverityAcross($rows));
    }

    /** @test */
    public function worst_severity_promotes_yellow_when_no_red(): void
    {
        $rows = [
            'UZS' => ['counted' => 600000, 'expected' => 608000],   // ~1.3% → green
            'USD' => ['counted' => 36,     'expected' => 40],       // 10% → yellow
        ];
        $this->assertSame('yellow', $this->c->worstSeverityAcross($rows));
    }

    /** @test */
    public function worst_severity_green_when_all_clean(): void
    {
        $rows = [
            'UZS' => ['counted' => 608000, 'expected' => 608000],
            'USD' => ['counted' => 40,     'expected' => 40],
        ];
        $this->assertSame('green', $this->c->worstSeverityAcross($rows));
    }
}
