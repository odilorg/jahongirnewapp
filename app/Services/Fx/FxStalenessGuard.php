<?php

declare(strict_types=1);

namespace App\Services\Fx;

use App\Exceptions\Fx\StaleFxRateException;
use App\Models\DailyExchangeRate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Single canonical staleness gate for the `daily_exchange_rates` table.
 *
 * Called at payment-session preparation time
 * (`BotPaymentService::preparePayment`) so both the cashier-bot path
 * and the Filament admin mixed-currency path (which also delegates to
 * `BotPaymentService::preparePayment` via
 * `RecordMixedCurrencySplitFromAdminAction`) refuse to continue when
 * the latest persisted FX row is operationally stale.
 *
 * # Semantics — v2 (2026-05-10)
 *
 * v1 (commit cb54bd2, rolled back) used a single hourly threshold
 * (`fetched_at < now - fx.stale_after_hours`, default 4h). That broke
 * normal afternoon operations: the morning cron runs once at 07:00
 * Tashkent, so by 11:00 the row was 4h old and the guard refused
 * payments for the rest of the day even on a perfectly healthy day.
 * Hourly-freshness semantics are wrong for a daily exchange-rate
 * system.
 *
 * v2 uses a hybrid check that matches the cron's actual cadence:
 *
 *   1. PRIMARY — `rate_date == today` in the app timezone.
 *      The morning cron writes one row per day with `rate_date=today`.
 *      A row with yesterday's rate_date means the cron failed (no row
 *      for today exists), and the operator must either re-run the
 *      cron or set a fresh manual rate via Filament admin.
 *
 *   2. SECONDARY — `fetched_at` not absurdly old (default <= 28 hours,
 *      env-tunable via `FX_FRESH_FETCHED_MAX_HOURS`).
 *      Catches the rare case where a row has today's `rate_date` but
 *      `fetched_at` is from much earlier (e.g. system clock drift,
 *      data fix that backdated the rate_date, or an admin manual row
 *      where someone set rate_date=today but kept the old fetched_at).
 *      28h gives one full day of slack from any 07:00 cron run plus
 *      4h of operational buffer.
 *
 * Both checks must pass for the guard to allow a session. Either
 * failing throws `StaleFxRateException`. Source field (cbu /
 * open.er-api / floatrates / manual) is NOT used to discriminate —
 * a manual override is the operator's escape valve when the cron
 * fails, but it must satisfy the same two checks (admin enters a
 * row with `rate_date=today` and `fetched_at=now`, both checks pass
 * trivially; admin who set `rate_date=yesterday` must update it to
 * today; admin row from yesterday with today's data is still treated
 * as yesterday by the primary check).
 *
 * # Configuration plumbing
 *
 * - `fx.fresh_fetched_max_hours` (default 28, env
 *   `FX_FRESH_FETCHED_MAX_HOURS`) controls the secondary cap.
 * - Values <= 0 clamp to 1 with a warning log so a misconfigured
 *   env can't silently disable the guard or shift the threshold
 *   into the future.
 * - The legacy `fx.stale_after_hours` config key from v1 is no
 *   longer read by this guard (kept in `config/fx.php` for
 *   backward-compat with any future consumer). The PRIMARY check
 *   is hardcoded "today" — no config knob there, since calendar-day
 *   is unambiguous.
 *
 * # Today's-timezone semantics
 *
 * "Today" means `Carbon::today()` evaluated in the app's default
 * timezone (typically `Asia/Tashkent` on production). Carbon's
 * `today()` reads `app.timezone` so the calendar boundary is the
 * operator's wall-clock midnight, not UTC midnight.
 *
 * # Tracked follow-up
 *
 * - #2 (cron-failure alert) and #3 (fallback sanity-band) layer on
 *   top of this guard. This service is intentionally narrow:
 *   detect-and-throw, no auto-refresh, no source-based bypass.
 */
final class FxStalenessGuard
{
    private int $freshFetchedMaxHours;

    /**
     * @param  int|null  $freshFetchedMaxHours  Override for the
     *                                          secondary `fetched_at` age cap (used by tests).
     *                                          Production callers pass null; the value is
     *                                          read from `config('fx.fresh_fetched_max_hours')`
     *                                          (default 28) at construction time. Values <= 0
     *                                          clamp to 1 with a warning log to prevent a
     *                                          misconfigured env from silently disabling the
     *                                          guard or shifting the threshold into the future.
     */
    public function __construct(?int $freshFetchedMaxHours = null)
    {
        $configured = $freshFetchedMaxHours
            ?? (int) config('fx.fresh_fetched_max_hours', 28);

        if ($configured <= 0) {
            Log::warning('FxStalenessGuard: configured fresh_fetched_max_hours out of range — clamping', [
                'configured_hours' => $configured,
                'clamped_to' => 1,
            ]);
            $configured = 1;
        }

        $this->freshFetchedMaxHours = $configured;
    }

    /**
     * Refuse to proceed if the latest `daily_exchange_rates` row fails
     * either the primary `rate_date == today` check or the secondary
     * `fetched_at <= fresh_fetched_max_hours` check.
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
                'fresh_fetched_max_hours' => $this->freshFetchedMaxHours,
            ]);

            throw new StaleFxRateException(
                'No FX rate available. Run `php artisan fx:push-payment-options` to seed today\'s rate, '
                .'or set a manual rate in Filament admin.'
            );
        }

        // ── Primary check: rate_date must be today (app timezone) ───
        $today = Carbon::today();
        $rateDate = $row->rate_date instanceof Carbon
            ? $row->rate_date
            : Carbon::parse((string) $row->rate_date);

        if (! $rateDate->isSameDay($today)) {
            Log::warning('FxStalenessGuard: latest rate_date is not today', [
                'row_id' => $row->id,
                'rate_date' => $rateDate->toDateString(),
                'today' => $today->toDateString(),
                'source' => $row->source,
                'fetched_at' => optional($row->fetched_at)->toIso8601String(),
            ]);

            throw new StaleFxRateException(sprintf(
                'Latest FX rate is for %s, not today (%s). The morning cron may have failed. '
                .'Run `php artisan fx:push-payment-options` or set a manual rate in Filament admin.',
                $rateDate->toDateString(),
                $today->toDateString(),
            ));
        }

        // ── Secondary check: fetched_at not absurdly old ────────────
        if ($row->fetched_at === null) {
            Log::warning('FxStalenessGuard: latest row has no fetched_at', [
                'row_id' => $row->id,
                'rate_date' => $rateDate->toDateString(),
                'source' => $row->source,
            ]);

            throw new StaleFxRateException(sprintf(
                'FX rate row #%d has no fetched_at timestamp. Cannot evaluate freshness; '
                .'reseed via `php artisan fx:push-payment-options`.',
                $row->id,
            ));
        }

        $threshold = Carbon::now()->subHours($this->freshFetchedMaxHours);

        if ($row->fetched_at->lt($threshold)) {
            $hoursOld = (int) $row->fetched_at->diffInHours(Carbon::now());
            Log::warning('FxStalenessGuard: fetched_at exceeds absurd-age cap', [
                'row_id' => $row->id,
                'rate_date' => $rateDate->toDateString(),
                'fetched_at' => $row->fetched_at->toIso8601String(),
                'hours_old' => $hoursOld,
                'fresh_fetched_max_hours' => $this->freshFetchedMaxHours,
                'source' => $row->source,
            ]);

            throw new StaleFxRateException(sprintf(
                'FX rate has rate_date=today but fetched_at=%s is %d hours old; max allowed %d. '
                .'Row likely stuck — reseed via `php artisan fx:push-payment-options` '
                .'or set a fresh manual rate in Filament admin.',
                $row->fetched_at->toDateTimeString(),
                $hoursOld,
                $this->freshFetchedMaxHours,
            ));
        }
    }

    /**
     * Non-throwing freshness check. Same semantics as `ensureFreshOrFail()`
     * but returns a bool instead of throwing — for read-only callers that
     * need to soft-degrade (e.g. cashier-bot guest-list price display
     * showing USD only when UZS conversion is unavailable).
     *
     * Single source of truth: delegates to `ensureFreshOrFail()` so the
     * primary + secondary checks (rate_date == today, fetched_at within
     * cap) cannot drift between read-only and money-write paths.
     *
     * Do NOT use this in a payment-collection or settlement code path —
     * that path MUST throw via `ensureFreshOrFail()` so refusal is loud
     * and traceable. This companion is for display-only code.
     */
    public function isFresh(): bool
    {
        try {
            $this->ensureFreshOrFail();

            return true;
        } catch (StaleFxRateException) {
            return false;
        }
    }

    /**
     * Non-throwing variant that returns the latest row when fresh, null
     * otherwise. Mirrors `isFresh()`'s semantics but hands back the row
     * itself so display callers can read `usd_uzs_rate` etc. without a
     * second lookup. Returns null when the rate is stale OR missing.
     *
     * Same caveat as `isFresh()`: never use this in money-write paths.
     */
    public function getFreshOrNull(): ?DailyExchangeRate
    {
        if (! $this->isFresh()) {
            return null;
        }

        return DailyExchangeRate::query()
            ->orderByDesc('rate_date')
            ->orderByDesc('id')
            ->first();
    }
}
