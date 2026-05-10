<?php

declare(strict_types=1);

namespace App\Services\Fx;

use App\Exceptions\Fx\StaleFxRateException;
use App\Models\DailyExchangeRate;
use Illuminate\Support\Facades\Log;

/**
 * Single canonical staleness gate for the `daily_exchange_rates` table.
 *
 * Called at payment-session preparation time
 * (`BotPaymentService::preparePayment`) so both the cashier-bot path
 * and the Filament admin mixed-currency path (which also delegates to
 * `BotPaymentService::preparePayment` via
 * `RecordMixedCurrencySplitFromAdminAction`) refuse to continue when
 * the latest persisted FX row is older than
 * `config('fx.stale_after_hours')`.
 *
 * Source-discrimination is intentional and uniform: ALL sources
 * (`cbu` / `open.er-api.com` / `floatrates` / `manual`) are subject to
 * the same freshness threshold. Operators who entered a manual rate
 * are still expected to refresh it before it ages out — the manual
 * path is the *escape valve when the cron fails*, not a permanent
 * exemption.
 *
 * Tracked-follow-up #2 (cron-failure alert) and #3 (fallback sanity-
 * band) layer on top of this guard. This service is intentionally
 * narrow: detect-and-throw, no auto-refresh, no override-by-source.
 */
final class FxStalenessGuard
{
    private int $maxAgeHours;

    /**
     * @param  int|null  $maxAgeHours  Override threshold (used by tests).
     *                                 Production callers pass null and
     *                                 the value is read from
     *                                 `config('fx.stale_after_hours')`
     *                                 (default 4) at construction time.
     *                                 Values <= 0 are clamped to 1 and a
     *                                 warning is logged — a misconfigured
     *                                 0 or negative would otherwise either
     *                                 throw on every payment session or
     *                                 silently shift the threshold into
     *                                 the future, both of which are worse
     *                                 than enforcing a strict 1-hour floor.
     */
    public function __construct(?int $maxAgeHours = null)
    {
        $configured = $maxAgeHours ?? (int) config('fx.stale_after_hours', 4);

        if ($configured <= 0) {
            Log::warning('FxStalenessGuard: configured threshold out of range — clamping', [
                'configured_hours' => $configured,
                'clamped_to'       => 1,
            ]);
            $configured = 1;
        }

        $this->maxAgeHours = $configured;
    }

    /**
     * Refuse to proceed if the latest `daily_exchange_rates` row is
     * older than the configured threshold (or missing entirely).
     *
     * @throws StaleFxRateException
     */
    public function ensureFreshOrFail(): void
    {
        // Pick the newest written row. The `id` tie-breaker correctly
        // resolves multiple rows on the same `rate_date` (e.g. cron run
        // followed by a manual override later in the day) only because
        // `daily_exchange_rates.id` is a monotonic auto-increment PK
        // populated by `Model::create()` on every write path. Manual
        // SQL inserts that bypass Model::create() and supply a lower
        // id would defeat this — flagged in the 2026-05-08 reviewer
        // pass; safe today because no path does that.
        $row = DailyExchangeRate::query()
            ->orderByDesc('rate_date')
            ->orderByDesc('id')
            ->first();

        if ($row === null) {
            // Cold-start case — should only happen on a fresh prod db
            // before the morning cron has ever run. Refuse loudly.
            Log::warning('FxStalenessGuard: no DailyExchangeRate row exists', [
                'max_allowed_hours' => $this->maxAgeHours,
            ]);
            throw new StaleFxRateException(
                'No FX rate available. Run `php artisan fx:push-payment-options` to seed today\'s rate, '
                . 'or set a manual rate in Filament admin.'
            );
        }

        if ($row->fetched_at === null) {
            Log::warning('FxStalenessGuard: latest row has no fetched_at', [
                'row_id' => $row->id,
                'rate_date' => optional($row->rate_date)->toDateString(),
                'source' => $row->source,
            ]);
            throw new StaleFxRateException(sprintf(
                'FX rate row #%d has no fetched_at timestamp. Cannot evaluate freshness; '
                . 'reseed via `php artisan fx:push-payment-options`.',
                $row->id,
            ));
        }

        $threshold = now()->subHours($this->maxAgeHours);

        if ($row->fetched_at->lt($threshold)) {
            $hoursOld = (int) $row->fetched_at->diffInHours(now());
            Log::warning('FxStalenessGuard: stale FX rate refused', [
                'row_id' => $row->id,
                'fetched_at' => $row->fetched_at->toIso8601String(),
                'hours_old' => $hoursOld,
                'max_allowed_hours' => $this->maxAgeHours,
                'source' => $row->source,
            ]);
            throw new StaleFxRateException(sprintf(
                'FX rate from %s is %d hours old; max allowed %d. '
                . 'Refresh via `php artisan fx:push-payment-options` or set a manual rate in Filament admin.',
                $row->fetched_at->toDateTimeString(),
                $hoursOld,
                $this->maxAgeHours,
            ));
        }
    }
}
