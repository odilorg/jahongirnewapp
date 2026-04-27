<?php

declare(strict_types=1);

namespace App\Services\Fx;

use App\Exceptions\Fx\InvalidFxOverrideException;

/**
 * The simplified FX policy in one place.
 *
 * Replaces the 4-tier OverrideTier evaluation. Stateless. Pure
 * arithmetic + config thresholds. No DTO, no model dependency, no DB
 * calls. Designed so it can be reused by Phase 2's recordPayment
 * switch + by Filament admin pages that want to flag overridden rows
 * without recomputing.
 *
 * Defaults (config('cashier.fx.*')):
 *   - 3% silent band
 *   - 15% reject threshold
 *
 * Both thresholds compare against |deviation_pct|. A negative
 * deviation (cashier used a rate BELOW reference, e.g. accepting more
 * UZS per USD than CBU) and a positive deviation (cashier used a
 * rate ABOVE reference) are gated symmetrically — fraud goes both
 * ways.
 */
final class FxThresholdGuard
{
    /**
     * Compute (signed) deviation_pct from a reference rate and the
     * actual rate the cashier used.
     *
     *   ((actual − reference) / reference) × 100
     *
     * Returns 0.0 if rates are equal. Throws if reference is zero
     * (caller's bug — there's no FX without a reference rate).
     */
    public function deviationPct(float $referenceRate, float $actualRate): float
    {
        if ($referenceRate <= 0.0) {
            throw new \InvalidArgumentException('reference_rate must be positive');
        }

        return round((($actualRate - $referenceRate) / $referenceRate) * 100, 4);
    }

    /**
     * Validate an FX override against the threshold rules. Throws
     * InvalidFxOverrideException on violation; returns silently on
     * pass.
     *
     * @param float       $deviationPct    signed; from deviationPct()
     * @param string|null $overrideReason  trimmed; null/empty if not provided
     */
    public function validate(float $deviationPct, ?string $overrideReason): void
    {
        $abs = abs($deviationPct);
        $reasonRequiredAt = (float) config('cashier.fx.override_reason_required_pct', 3.0);
        $hardBlockAt      = (float) config('cashier.fx.hard_block_pct', 15.0);

        if ($abs > $hardBlockAt) {
            throw new InvalidFxOverrideException(sprintf(
                'Курс отличается от системного на %.2f%%. Это превышает максимально допустимое отклонение (%.1f%%). Проверьте введённое значение.',
                $abs,
                $hardBlockAt,
            ));
        }

        $reason = trim((string) $overrideReason);
        if ($abs > $reasonRequiredAt && $reason === '') {
            throw new InvalidFxOverrideException(sprintf(
                'Отклонение курса (%.2f%%) превышает безмолвный порог (%.1f%%). Укажите причину изменения курса.',
                $abs,
                $reasonRequiredAt,
            ));
        }
    }

    /**
     * Convenience: did this row hit the override path at all?
     * Mirrors what the Phase 1 column will store as `was_overridden`.
     */
    public function wasOverridden(float $deviationPct): bool
    {
        return abs($deviationPct) > 0.0001; // tolerate float epsilon
    }
}
