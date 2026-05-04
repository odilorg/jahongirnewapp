<?php

declare(strict_types=1);

namespace App\Services\CashierBot;

/**
 * Sanity checks for cashier-shift close counts.
 *
 * Two responsibilities:
 *   - detectMissingZeros() — catches the order-of-magnitude typo class
 *     (608 vs 608,000) before the operator commits.
 *   - classifySeverity() — bands the discrepancy so the UI can adjust
 *     friction: green = silent, yellow = warn, red = hard-stop + reason.
 *
 * Real-world incident 2026-05-04: Aziz typed 608 instead of 608,000 at
 * close; bot accepted silently; balance carried to next shift; UI showed
 * 608 UZS to the next operator. The system detected the discrepancy
 * (logged it, alerted owner) but treated it as informational, not
 * transactional risk.
 */
class CashCountSanityChecker
{
    /** Tolerance when comparing counted vs expected after a magnitude shift. */
    private const MAGNITUDE_TOLERANCE_PCT = 2.0;

    /** Severity thresholds (% of max(|counted|, |expected|)). */
    public const SEVERITY_YELLOW_PCT = 5.0;
    public const SEVERITY_RED_PCT    = 20.0;

    /**
     * Did the operator drop or add zeros? Returns the *suggested correction*
     * (i.e. what the operator probably meant) or null if no typo class fits.
     *
     * Patterns detected:
     *   - counted is 10× / 100× / 1000× too small  (dropped 1 / 2 / 3 zeros)
     *   - counted is 10× / 100× / 1000× too large  (added 1 / 2 / 3 extras)
     *
     * Both directions, ±2% tolerance to absorb genuine partial-cash drift.
     * Edge: ignores the all-zero case where the comparison is meaningless.
     */
    public function detectMissingZeros(float $counted, float $expected): ?float
    {
        if ($expected <= 0 || $counted <= 0) {
            return null; // 0 inputs are normal (no transactions / empty drawer)
        }

        // Don't flag tiny round-up differences — only suspicious ratios.
        $ratio = $expected / $counted;

        // counted too small — operator dropped zeros
        foreach ([10, 100, 1000] as $factor) {
            if ($this->withinTolerance($counted * $factor, $expected)) {
                return $counted * $factor;
            }
        }

        // counted too large — operator added extra zeros
        foreach ([10, 100, 1000] as $factor) {
            if ($this->withinTolerance($counted / $factor, $expected)) {
                return $counted / $factor;
            }
        }

        return null;
    }

    /**
     * Severity band for a single-currency discrepancy.
     *   green  → |diff| / max(counted, expected) < 5%
     *   yellow → 5–20%
     *   red    → > 20%
     *
     * The denominator is max(counted, expected) so a 100% loss
     * (counted=0, expected=N) classifies as red, not as "infinite".
     */
    public function classifySeverity(float $counted, float $expected): string
    {
        $diff = abs($counted - $expected);
        $base = max(abs($counted), abs($expected));

        if ($base < 0.01) {
            return 'green'; // both effectively zero
        }

        $pct = ($diff / $base) * 100.0;

        if ($pct >= self::SEVERITY_RED_PCT) {
            return 'red';
        }
        if ($pct >= self::SEVERITY_YELLOW_PCT) {
            return 'yellow';
        }
        return 'green';
    }

    /**
     * Worst severity across multiple currencies — drives the close-screen
     * UX (any single red currency promotes the whole close to red).
     *
     * @param array<string, array{counted: float, expected: float}> $rows
     */
    public function worstSeverityAcross(array $rows): string
    {
        $worst = 'green';
        foreach ($rows as $row) {
            $sev = $this->classifySeverity((float) $row['counted'], (float) $row['expected']);
            if ($sev === 'red') {
                return 'red';
            }
            if ($sev === 'yellow' && $worst === 'green') {
                $worst = 'yellow';
            }
        }
        return $worst;
    }

    private function withinTolerance(float $a, float $b): bool
    {
        if ($b == 0.0) {
            return abs($a) < 0.01;
        }
        $deltaPct = (abs($a - $b) / abs($b)) * 100.0;
        return $deltaPct <= self::MAGNITUDE_TOLERANCE_PCT;
    }
}
