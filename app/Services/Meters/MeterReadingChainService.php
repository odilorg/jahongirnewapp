<?php

declare(strict_types=1);

namespace App\Services\Meters;

use App\Exceptions\Meters\InvalidMeterReadingException;
use App\Models\UtilityUsage;
use Illuminate\Support\Carbon;

/**
 * Single source of truth for the meter-reading chain rules.
 *
 * This service exists because the audit found 7 drift rows + 3 broken
 * chains in production caused by free-form meter_previous entry. The
 * Filament form is now a courtesy layer — these rules are enforced in
 * UtilityUsage::saving() too, so back-door writes (tinker, batch,
 * future API, console seeders) cannot bypass them.
 *
 * Phase 1 contract:
 *   - meter_previous defaults to the prior reading's meter_latest, or 0
 *     for the first reading on a meter.
 *   - meter_latest >= meter_previous unless is_meter_reset = true.
 *   - usage_date must be strictly after the latest existing reading on
 *     the same meter (no backdating yet — Phase 2 will add a reviewed
 *     backfill mode).
 *   - meter_previous_override requires a non-empty reason.
 *   - meter_difference is always recomputed from latest-previous, with a
 *     reset-row override defined below.
 */
final class MeterReadingChainService
{
    /**
     * Last reading on this meter that's not the row currently being
     * saved. Returns null if this is the first reading.
     */
    public function lastReadingFor(int $meterId, ?int $excludeId = null): ?UtilityUsage
    {
        $query = UtilityUsage::query()
            ->where('meter_id', $meterId)
            ->orderByDesc('usage_date')
            ->orderByDesc('id');

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->first();
    }

    /**
     * Auto-fill value for meter_previous on a brand-new reading. The
     * Filament form calls this when meter_id changes; the saving guard
     * trusts whatever value finally arrives but validates the chain.
     */
    public function autoFillPrevious(int $meterId): int
    {
        $last = $this->lastReadingFor($meterId);

        return $last ? (int) $last->meter_latest : 0;
    }

    /**
     * Validate a reading against the chain. Throws on first violation.
     *
     * @throws InvalidMeterReadingException
     */
    public function validate(UtilityUsage $reading): void
    {
        if (! $reading->meter_id) {
            throw new InvalidMeterReadingException('Не выбран счётчик.');
        }

        $latest = (int) $reading->meter_latest;
        $previous = (int) $reading->meter_previous;
        $isReset = (bool) $reading->is_meter_reset;
        $overridden = (bool) $reading->meter_previous_overridden;
        $reason = trim((string) $reading->meter_previous_override_reason);

        // Override toggle requires a reason — otherwise the audit trail
        // for the manual edit is empty.
        if ($overridden && $reason === '') {
            throw new InvalidMeterReadingException(
                'Укажите причину ручного изменения предыдущего показания.'
            );
        }
        if (! $overridden && $reason !== '') {
            // Strip stale reason so we don't keep an explanation for an
            // override that's no longer in effect.
            $reading->meter_previous_override_reason = null;
        }

        // Reset-vs-regression rule: only a hardware reset legitimately
        // produces meter_latest < meter_previous. Anything else is a
        // typo and would silently double-bill the customer.
        if ($latest < $previous && ! $isReset) {
            throw new InvalidMeterReadingException(
                'Текущее показание меньше предыдущего. Если счётчик был сброшен или заменён, отметьте «Сброс счётчика».'
            );
        }
        if ($latest >= $previous && $isReset && $previous !== 0) {
            // A reset that didn't actually go down is almost certainly
            // a misclick. previous=0 is the canonical reset shape.
            throw new InvalidMeterReadingException(
                'Сброс счётчика отмечен, но текущее значение не меньше предыдущего. Снимите галочку или установите предыдущее значение в 0.'
            );
        }

        $last = $this->lastReadingFor((int) $reading->meter_id, $reading->id);

        if ($last) {
            // No backdating — the chain is strictly time-ordered.
            // Backfill of historical data needs its own UI (Phase 2)
            // because it has to recompute downstream meter_previous values.
            $newDate = Carbon::parse($reading->usage_date)->startOfDay();
            $lastDate = Carbon::parse($last->usage_date)->startOfDay();

            if ($newDate->lessThanOrEqualTo($lastDate)) {
                throw new InvalidMeterReadingException(sprintf(
                    'Дата показания (%s) должна быть позже последнего существующего показания (%s).',
                    $newDate->toDateString(),
                    $lastDate->toDateString(),
                ));
            }

            // Default-mode chain integrity: when the operator did not
            // override, meter_previous MUST equal the prior meter_latest.
            // (When overridden, we've already required a reason, so the
            // drift is intentional and audit-trailed.)
            if (! $overridden && ! $isReset && $previous !== (int) $last->meter_latest) {
                throw new InvalidMeterReadingException(sprintf(
                    'Предыдущее показание (%d) не совпадает с последним показанием в системе (%d). Используйте автозаполнение или отметьте «Изменить предыдущее значение» с указанием причины.',
                    $previous,
                    (int) $last->meter_latest,
                ));
            }
        } else {
            // First-ever reading: prev=0 is the only sensible default.
            // We don't hard-block other values because someone might
            // legitimately import an in-progress meter, but require the
            // override toggle so it's visible.
            if ($previous !== 0 && ! $overridden) {
                throw new InvalidMeterReadingException(
                    'Это первое показание счётчика. Если предыдущее значение не равно 0, отметьте «Изменить предыдущее значение» и укажите причину.'
                );
            }
        }
    }

    /**
     * Authoritative meter_difference for a reading.
     *
     * Reset rule (Option A from the spec): for a reset row, the new
     * meter_latest IS the entire usage of the period since the last
     * reading — meter_previous is conventionally 0 in that case so the
     * arithmetic naturally matches.
     */
    public function differenceFor(UtilityUsage $reading): int
    {
        return (int) $reading->meter_latest - (int) $reading->meter_previous;
    }
}
