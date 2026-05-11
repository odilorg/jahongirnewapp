<?php

declare(strict_types=1);

namespace App\Services\Expenses;

use App\Models\CashExpense;
use App\Models\Expense;
use App\Services\ExchangeRateService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Monthly petty-cash → main hotel-ops expenses consolidation.
 *
 * Operator-triggered (via Filament header action). Reads cash_expenses for a
 * given month, converts FX rows to UZS using ExchangeRateService, creates a
 * matching `expenses` row per cash_expense, and stamps consolidated_at on the
 * source row.
 *
 * Idempotency: WHERE consolidated_at IS NULL gate. A re-run posts 0 rows.
 *
 * All-or-nothing: the whole month runs inside a single transaction. Any failure
 * (missing FX rate, DB error) rolls back the entire batch — no half-posted month.
 * This is the simpler, safer choice for v1; per-row resilience comes later if
 * pain shows up.
 *
 * Rejected cash_expenses are skipped (rejected_at IS NOT NULL). These were
 * declined by the owner via the Telegram approval flow and must NOT post.
 */
class ConsolidatePettyCashService
{
    public function __construct(private readonly ExchangeRateService $exchangeRates) {}

    /**
     * Consolidate one month's petty-cash rows into main expenses.
     *
     * @param  CarbonInterface|string  $month  Carbon date inside the target month, or 'YYYY-MM' string.
     * @param  int  $hotelId  Required — expenses.hotel_id is NOT NULL.
     * @param  int  $actorUserId  Stamps expenses.created_by for audit attribution.
     * @return int Number of cash_expenses rows posted to main expenses.
     */
    public function consolidateMonth(CarbonInterface|string $month, int $hotelId, int $actorUserId): int
    {
        $period = $month instanceof CarbonInterface
            ? $month->copy()->startOfMonth()
            : Carbon::createFromFormat('Y-m', $month)->startOfMonth();

        $start = $period->copy()->startOfMonth();
        $end = $period->copy()->endOfMonth();

        return DB::transaction(function () use ($start, $end, $hotelId, $actorUserId): int {
            $eligible = CashExpense::query()
                ->whereNull('consolidated_at')
                ->whereNull('rejected_at')
                ->whereBetween('occurred_at', [$start, $end])
                ->lockForUpdate()
                ->get();

            $posted = 0;
            foreach ($eligible as $cash) {
                $uzsAmount = $this->toUzs($cash);

                Expense::create([
                    'expense_category_id' => $cash->expense_category_id,
                    'name' => $this->buildName($cash),
                    'expense_date' => $cash->occurred_at->toDateString(),
                    'amount' => $uzsAmount,
                    'hotel_id' => $hotelId,
                    'payment_type' => 'naqd',
                    'created_by' => $actorUserId,
                    'cash_expense_id' => $cash->id,
                ]);

                // Per feedback_no_mass_assign_for_system_state: never $model->update()
                // for system-state writes. forceFill->save bypasses fillable silently-fail.
                $cash->forceFill(['consolidated_at' => now()])->save();

                $posted++;
            }

            return $posted;
        });
    }

    /**
     * Convert cash_expense native amount to UZS for the main expenses ledger.
     *
     * UZS rows pass through. FX rows (USD/EUR/RUB) use live cached rates from
     * ExchangeRateService — Phase 2 may snapshot the rate on the consolidated
     * row for audit; v1 trusts the rate-at-consolidation-time.
     *
     * @throws \DomainException when an FX rate is unavailable (whole batch rolls back).
     */
    private function toUzs(CashExpense $cash): float
    {
        $currency = strtoupper($cash->currency ?? 'UZS');
        $amount = (float) $cash->amount;

        if ($currency === 'UZS') {
            return $amount;
        }

        $rate = match ($currency) {
            'USD' => $this->exchangeRates->getUsdToUzs(),
            'EUR' => $this->exchangeRates->getEurToUzs(),
            'RUB' => $this->exchangeRates->getRubToUzs(),
            default => null,
        };

        if ($rate === null || empty($rate['rate'])) {
            throw new \DomainException(
                "Cannot consolidate cash_expense #{$cash->id}: {$currency} rate unavailable. "
                .'Retry when ExchangeRateService is reachable, or convert this row manually.'
            );
        }

        // MoneyCast handles the canonical ×100 rounding on save; pre-rounding
        // here would just create a second rounding boundary for no benefit.
        return $amount * (float) $rate['rate'];
    }

    /**
     * Human-readable name on the consolidated expenses row.
     * Truncated to fit expenses.name (VARCHAR — no explicit length on existing schema; Laravel default 255).
     */
    private function buildName(CashExpense $cash): string
    {
        $prefix = '[Petty cash] ';
        $body = trim((string) $cash->description);
        $name = $prefix.($body !== '' ? $body : 'expense');

        return mb_substr($name, 0, 255);
    }
}
