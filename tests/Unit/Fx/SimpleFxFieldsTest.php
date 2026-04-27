<?php

declare(strict_types=1);

namespace Tests\Unit\Fx;

use App\Services\Fx\SimpleFxFields;
use Tests\TestCase;

/**
 * Pure-arithmetic tests for the dual-write derivation. No DB.
 */
final class SimpleFxFieldsTest extends TestCase
{
    public function test_empty_holds_no_partial_inconsistent_state(): void
    {
        // Invariant: when reference_rate is NULL, deviation_pct must be 0.0
        // and was_overridden must be false. A reader can never see "no rate
        // but flagged as override" or "no rate but non-zero deviation".
        $fields = SimpleFxFields::empty();
        $this->assertNull($fields->referenceRate);
        $this->assertNull($fields->actualRate);
        $this->assertSame(0.0, $fields->deviationPct);
        $this->assertFalse($fields->wasOverridden);
        $this->assertNull($fields->overrideReason);
    }

    public function test_missing_reference_rate_yields_no_partial_state(): void
    {
        // Regression: ExchangeRateService failure / EUR-RUB-paid path / missing
        // usd_equivalent must NOT produce a row with NULL rate but a non-zero
        // deviation or was_overridden=true. toArray() output is what writes
        // to the DB row, so pin the columns directly.
        $missingRate = SimpleFxFields::deriveForUzsPayment(
            amountPaidUzs: 1_270_000,
            usdEquivalentPaid: 100.0,
            referenceRateUzsPerUsd: null,
            overrideReason: 'manager-said-ok',
        );
        $missingUsd = SimpleFxFields::deriveForUzsPayment(
            amountPaidUzs: 1_270_000,
            usdEquivalentPaid: null,
            referenceRateUzsPerUsd: 12700.0,
            overrideReason: 'manager-said-ok',
        );

        foreach ([$missingRate, $missingUsd] as $f) {
            $row = $f->toArray();
            $this->assertNull($row['reference_rate']);
            $this->assertNull($row['actual_rate']);
            $this->assertSame(0.0, $row['deviation_pct']);
            $this->assertFalse($row['was_overridden']);
            $this->assertNull($row['override_reason']);
        }
    }

    public function test_uzs_payment_at_reference_rate_has_zero_deviation(): void
    {
        // 100 USD × 12,700 UZS/USD = 1,270,000 UZS expected.
        $fields = SimpleFxFields::deriveForUzsPayment(
            amountPaidUzs: 1_270_000,
            usdEquivalentPaid: 100.0,
            referenceRateUzsPerUsd: 12700.0,
            overrideReason: null,
        );

        $this->assertEquals(12700.0, $fields->referenceRate);
        $this->assertEquals(12700.0, $fields->actualRate);
        $this->assertEquals(0.0, $fields->deviationPct);
        $this->assertFalse($fields->wasOverridden);
    }

    public function test_uzs_payment_above_reference_records_positive_deviation(): void
    {
        // Cashier accepted 1,295,400 UZS for 100 USD = 12,954 UZS/USD (≈+2%).
        $fields = SimpleFxFields::deriveForUzsPayment(
            amountPaidUzs: 1_295_400,
            usdEquivalentPaid: 100.0,
            referenceRateUzsPerUsd: 12700.0,
            overrideReason: null,
        );

        $this->assertEquals(12954.0, $fields->actualRate);
        $this->assertEqualsWithDelta(2.0, $fields->deviationPct, 0.0001);
        $this->assertTrue($fields->wasOverridden);
    }

    public function test_uzs_payment_below_reference_records_negative_deviation(): void
    {
        $fields = SimpleFxFields::deriveForUzsPayment(
            amountPaidUzs: 1_257_300,
            usdEquivalentPaid: 100.0,
            referenceRateUzsPerUsd: 12700.0,
            overrideReason: 'Согласовано',
        );

        $this->assertLessThan(0, $fields->deviationPct);
        $this->assertEqualsWithDelta(-1.0, $fields->deviationPct, 0.0001);
        $this->assertTrue($fields->wasOverridden);
        $this->assertSame('Согласовано', $fields->overrideReason);
    }

    public function test_whitespace_only_override_reason_is_normalized_to_null(): void
    {
        $fields = SimpleFxFields::deriveForUzsPayment(
            amountPaidUzs: 1_270_000,
            usdEquivalentPaid: 100.0,
            referenceRateUzsPerUsd: 12700.0,
            overrideReason: '   ',
        );
        $this->assertNull($fields->overrideReason);
    }

    public function test_missing_usd_equivalent_returns_empty(): void
    {
        $fields = SimpleFxFields::deriveForUzsPayment(
            amountPaidUzs: 1_270_000,
            usdEquivalentPaid: null,
            referenceRateUzsPerUsd: 12700.0,
            overrideReason: 'r',
        );
        $this->assertNull($fields->referenceRate);
        $this->assertFalse($fields->wasOverridden);
    }

    public function test_missing_reference_rate_returns_empty(): void
    {
        $fields = SimpleFxFields::deriveForUzsPayment(
            amountPaidUzs: 1_270_000,
            usdEquivalentPaid: 100.0,
            referenceRateUzsPerUsd: null,
            overrideReason: null,
        );
        $this->assertNull($fields->actualRate);
    }

    public function test_to_array_matches_column_names(): void
    {
        $fields = SimpleFxFields::deriveForUzsPayment(
            amountPaidUzs: 1_295_400,
            usdEquivalentPaid: 100.0,
            referenceRateUzsPerUsd: 12700.0,
            overrideReason: 'rsn',
        );

        $arr = $fields->toArray();
        $this->assertEqualsCanonicalizing(
            ['reference_rate', 'actual_rate', 'deviation_pct', 'was_overridden', 'override_reason'],
            array_keys($arr),
        );
        $this->assertEquals(12700.0, $arr['reference_rate']);
        $this->assertSame(true, $arr['was_overridden']);
    }
}
