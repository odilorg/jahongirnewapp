<?php

namespace App\Services;

use App\Models\User;
use App\Models\CashierShift;
use App\Models\CashTransaction;
use App\Enums\ShiftStatus;
use App\Enums\TransactionType;
use App\Enums\Currency;
use App\Enums\ReportPeriod;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class AdvancedReportService
{
    /**
     * REPORT 1: Date Range Financial Summary
     */
    public function getDateRangeFinancialSummary(
        User $manager,
        Carbon $startDate,
        Carbon $endDate,
        ?string $locationName = null,
        ?Currency $currency = null
    ): array {
        if (!$this->isManagerAuthorized($manager)) {
            return ['error' => 'Unauthorized'];
        }

        // Get shifts in date range
        $shifts = CashierShift::whereBetween('opened_at', [$startDate, $endDate])
            ->when($locationName, function($q) use ($locationName) {
                $q->whereHas('cashDrawer', fn($query) => $query->where('location', $locationName));
            })
            ->with(['transactions', 'user', 'cashDrawer'])
            ->get();

        // Get all transactions
        $transactions = $shifts->flatMap->transactions;

        if ($currency) {
            $transactions = $transactions->where('currency', $currency);
        }

        // Calculate metrics
        $revenue = $transactions->where('type', TransactionType::IN)->sum('amount');
        $expenses = $transactions->where('type', TransactionType::OUT)->sum('amount');
        $exchanges = $transactions->where('type', TransactionType::IN_OUT)->sum('amount');
        $netCashFlow = $revenue - $expenses;

        // Per-currency breakdown
        $currencyBreakdown = [];
        foreach (Currency::cases() as $curr) {
            $currTxns = $transactions->where('currency', $curr);
            if ($currTxns->isEmpty()) continue;

            $currencyBreakdown[$curr->value] = [
                'revenue' => $currTxns->where('type', TransactionType::IN)->sum('amount'),
                'expenses' => $currTxns->where('type', TransactionType::OUT)->sum('amount'),
                'net' => $currTxns->where('type', TransactionType::IN)->sum('amount') -
                         $currTxns->where('type', TransactionType::OUT)->sum('amount'),
            ];
        }

        // Calculate period metrics
        $dayCount = $startDate->diffInDays($endDate) + 1;

        // Get previous period for comparison
        $previousPeriod = $this->getPreviousPeriodMetrics($startDate, $endDate, $locationName, $currency);

        return [
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'days' => $dayCount,
            ],
            'location' => $locationName ?? 'All Locations',
            'currency_filter' => $currency?->value ?? 'All Currencies',

            'summary' => [
                'revenue' => $revenue,
                'expenses' => $expenses,
                'exchanges' => $exchanges,
                'net_cash_flow' => $netCashFlow,
            ],

            'shifts' => [
                'total' => $shifts->count(),
                'open' => $shifts->where('status', ShiftStatus::OPEN)->count(),
                'closed' => $shifts->where('status', ShiftStatus::CLOSED)->count(),
            ],

            'transactions' => [
                'total' => $transactions->count(),
                'cash_in' => $transactions->where('type', TransactionType::IN)->count(),
                'cash_out' => $transactions->where('type', TransactionType::OUT)->count(),
                'exchanges' => $transactions->where('type', TransactionType::IN_OUT)->count(),
            ],

            'currency_breakdown' => $currencyBreakdown,

            'daily_averages' => [
                'revenue_per_day' => $dayCount > 0 ? $revenue / $dayCount : 0,
                'transactions_per_day' => $dayCount > 0 ? $transactions->count() / $dayCount : 0,
                'shifts_per_day' => $dayCount > 0 ? $shifts->count() / $dayCount : 0,
                'revenue_per_shift' => $shifts->count() > 0 ? $revenue / $shifts->count() : 0,
            ],

            'comparison' => [
                'revenue_change_pct' => $this->calculatePercentChange($revenue, $previousPeriod['revenue']),
                'revenue_change_abs' => $revenue - $previousPeriod['revenue'],
                'transactions_change_pct' => $this->calculatePercentChange($transactions->count(), $previousPeriod['transactions']),
                'efficiency_change_pct' => $this->calculatePercentChange(
                    $shifts->count() > 0 ? $revenue / $shifts->count() : 0,
                    $previousPeriod['revenue_per_shift']
                ),
            ],
        ];
    }

    /**
     * REPORT 2: Currency Exchange Report
     */
    public function getCurrencyExchangeReport(
        User $manager,
        Carbon $startDate,
        Carbon $endDate,
        ?string $locationName = null
    ): array {
        if (!$this->isManagerAuthorized($manager)) {
            return ['error' => 'Unauthorized'];
        }

        // Get all exchange transactions (IN_OUT type)
        $shifts = CashierShift::whereBetween('opened_at', [$startDate, $endDate])
            ->when($locationName, function($q) use ($locationName) {
                $q->whereHas('cashDrawer', fn($query) => $query->where('location', $locationName));
            })
            ->with(['transactions' => fn($q) => $q->where('type', TransactionType::IN_OUT)])
            ->get();

        $exchanges = $shifts->flatMap->transactions;

        // Group by currency
        $exchangesByCurrency = $exchanges->groupBy('currency');

        // Calculate hourly patterns
        $exchangesByHour = $exchanges->groupBy(fn($e) => $e->occurred_at->hour)
            ->map(fn($group) => $group->count())
            ->sortKeys();

        return [
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'location' => $locationName ?? 'All Locations',

            'summary' => [
                'total_exchanges' => $exchanges->count(),
                'total_value_uzs_equiv' => $exchanges->where('currency', Currency::UZS)->sum('amount'),
                'avg_exchange_amount' => $exchanges->avg('amount') ?? 0,
            ],

            'by_currency' => $exchangesByCurrency->map(fn($group, $currency) => [
                'currency' => $currency,
                'count' => $group->count(),
                'total_amount' => $group->sum('amount'),
                'avg_amount' => $group->avg('amount') ?? 0,
            ])->values(),

            'hourly_pattern' => $exchangesByHour,

            'largest_exchanges' => $exchanges->sortByDesc('amount')->take(10)->map(fn($e) => [
                'amount' => $e->amount,
                'currency' => $e->currency->value,
                'occurred_at' => $e->occurred_at,
                'shift_id' => $e->cashier_shift_id,
                'cashier' => $e->shift->user->name ?? 'N/A',
            ])->values(),
        ];
    }

    /**
     * REPORT 3: Discrepancy & Variance Report
     */
    public function getDiscrepancyVarianceReport(
        User $manager,
        Carbon $startDate,
        Carbon $endDate,
        ?string $locationName = null,
        float $minDiscrepancyAmount = 0
    ): array {
        if (!$this->isManagerAuthorized($manager)) {
            return ['error' => 'Unauthorized'];
        }

        // Get all closed shifts
        $shifts = CashierShift::whereBetween('opened_at', [$startDate, $endDate])
            ->where('status', ShiftStatus::CLOSED)
            ->when($locationName, function($q) use ($locationName) {
                $q->whereHas('cashDrawer', fn($query) => $query->where('location', $locationName));
            })
            ->with(['user', 'cashDrawer', 'endSaldos'])
            ->get();

        // Find shifts with discrepancies using endSaldos
        $shiftsWithDiscrepancies = $shifts->filter(function($shift) use ($minDiscrepancyAmount) {
            if (!$shift->endSaldos || $shift->endSaldos->isEmpty()) return false;

            foreach ($shift->endSaldos as $endSaldo) {
                if (abs($endSaldo->discrepancy ?? 0) >= $minDiscrepancyAmount) {
                    return true;
                }
            }
            return false;
        });

        // Calculate total discrepancy
        $totalDiscrepancy = 0;
        foreach ($shiftsWithDiscrepancies as $shift) {
            if (!$shift->endSaldos) continue;
            foreach ($shift->endSaldos as $endSaldo) {
                $totalDiscrepancy += abs($endSaldo->discrepancy ?? 0);
            }
        }

        // Group by cashier
        $byCashier = $shiftsWithDiscrepancies->groupBy('user_id')->map(function($group) use ($shifts) {
            $cashier = $group->first()->user;
            $totalShifts = $shifts->where('user_id', $cashier->id)->count();
            $discrepancyShifts = $group->count();

            $totalDiscrepancy = 0;
            foreach ($group as $shift) {
                if (!$shift->endSaldos) continue;
                foreach ($shift->endSaldos as $endSaldo) {
                    $totalDiscrepancy += abs($endSaldo->discrepancy ?? 0);
                }
            }

            return [
                'cashier_id' => $cashier->id,
                'cashier_name' => $cashier->name,
                'total_shifts' => $totalShifts,
                'discrepancy_shifts' => $discrepancyShifts,
                'accuracy_rate' => $totalShifts > 0 ? ($totalShifts - $discrepancyShifts) / $totalShifts * 100 : 100,
                'total_discrepancy_amount' => $totalDiscrepancy,
                'avg_discrepancy_amount' => $discrepancyShifts > 0 ? $totalDiscrepancy / $discrepancyShifts : 0,
            ];
        })->sortByDesc('total_discrepancy_amount')->values();

        // Top 10 largest discrepancies
        $topDiscrepancies = [];
        foreach ($shiftsWithDiscrepancies as $shift) {
            if (!$shift->endSaldos) continue;
            foreach ($shift->endSaldos as $endSaldo) {
                if (abs($endSaldo->discrepancy ?? 0) > 0) {
                    $topDiscrepancies[] = [
                        'shift_id' => $shift->id,
                        'cashier_name' => $shift->user->name,
                        'location' => $shift->cashDrawer->location ?? 'N/A',
                        'currency' => $endSaldo->currency->value,
                        'expected' => $endSaldo->expected_end_saldo,
                        'counted' => $endSaldo->counted_end_saldo,
                        'discrepancy' => $endSaldo->discrepancy,
                        'occurred_at' => $shift->closed_at,
                        'reason' => $shift->discrepancy_reason,
                    ];
                }
            }
        }

        $topDiscrepancies = collect($topDiscrepancies)
            ->sortByDesc(fn($d) => abs($d['discrepancy']))
            ->take(10)
            ->values();

        return [
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'location' => $locationName ?? 'All Locations',

            'summary' => [
                'total_shifts' => $shifts->count(),
                'shifts_with_discrepancies' => $shiftsWithDiscrepancies->count(),
                'discrepancy_rate' => $shifts->count() > 0 ? $shiftsWithDiscrepancies->count() / $shifts->count() * 100 : 0,
                'total_discrepancy_amount' => $totalDiscrepancy,
                'avg_discrepancy_amount' => $shiftsWithDiscrepancies->count() > 0 ? $totalDiscrepancy / $shiftsWithDiscrepancies->count() : 0,
            ],

            'by_cashier' => $byCashier,

            'top_discrepancies' => $topDiscrepancies,

            'unresolved_count' => 0, // Future feature: track resolution status
        ];
    }

    /**
     * REPORT 4: Executive Summary Dashboard
     */
    public function getExecutiveSummaryDashboard(
        User $manager,
        ReportPeriod $period = ReportPeriod::TODAY,
        ?Carbon $customStartDate = null,
        ?Carbon $customEndDate = null
    ): array {
        if (!$this->isManagerAuthorized($manager)) {
            return ['error' => 'Unauthorized'];
        }

        // Get date range
        if ($period === ReportPeriod::CUSTOM) {
            if (!$customStartDate || !$customEndDate) {
                return ['error' => 'Custom period requires start and end dates'];
            }
            $startDate = $customStartDate;
            $endDate = $customEndDate;
        } else {
            [$startDate, $endDate] = $period->getDateRange();
        }

        // Get financial summary
        $financialSummary = $this->getDateRangeFinancialSummary($manager, $startDate, $endDate);
        if (isset($financialSummary['error'])) {
            return $financialSummary;
        }

        // Get discrepancy summary
        $discrepancySummary = $this->getDiscrepancyVarianceReport($manager, $startDate, $endDate);
        if (isset($discrepancySummary['error'])) {
            return $discrepancySummary;
        }

        // Get active shifts
        $activeShifts = CashierShift::where('status', ShiftStatus::OPEN)->count();

        // Get top performers (by revenue)
        $topPerformers = $this->getTopPerformers($startDate, $endDate, 5);

        // Get alerts
        $alerts = $this->getAlerts();

        return [
            'period' => [
                'label' => $period->getLabel(),
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],

            'financial' => [
                'revenue' => $financialSummary['summary']['revenue'],
                'revenue_change_pct' => $financialSummary['comparison']['revenue_change_pct'],
                'transactions' => $financialSummary['transactions']['total'],
                'transactions_change_pct' => $financialSummary['comparison']['transactions_change_pct'],
                'avg_transaction_value' => $financialSummary['transactions']['total'] > 0
                    ? $financialSummary['summary']['revenue'] / $financialSummary['transactions']['total']
                    : 0,
                'net_cash_flow' => $financialSummary['summary']['net_cash_flow'],
                'currency_breakdown' => $financialSummary['currency_breakdown'],
            ],

            'operations' => [
                'total_shifts' => $financialSummary['shifts']['total'],
                'active_shifts' => $activeShifts,
                'avg_shifts_per_day' => $financialSummary['daily_averages']['shifts_per_day'],
                'efficiency' => $financialSummary['daily_averages']['revenue_per_shift'],
            ],

            'quality' => [
                'accuracy_rate' => 100 - $discrepancySummary['summary']['discrepancy_rate'],
                'total_discrepancies' => $discrepancySummary['summary']['shifts_with_discrepancies'],
                'total_discrepancy_amount' => $discrepancySummary['summary']['total_discrepancy_amount'],
                'quality_score' => $this->calculateQualityScore($discrepancySummary),
            ],

            'top_performers' => $topPerformers,

            'alerts' => $alerts,
        ];
    }

    // ==================== HELPER METHODS ====================

    /**
     * Check if user is authorized as manager
     */
    protected function isManagerAuthorized(User $user): bool
    {
        return $user->hasAnyRole(['manager', 'super_admin']);
    }

    /**
     * Get previous period metrics for comparison
     */
    protected function getPreviousPeriodMetrics(
        Carbon $startDate,
        Carbon $endDate,
        ?string $locationName,
        ?Currency $currency
    ): array {
        $duration = $startDate->diffInDays($endDate);
        $previousStart = $startDate->copy()->subDays($duration + 1);
        $previousEnd = $endDate->copy()->subDays($duration + 1);

        // Simplified previous period calculation
        $previousShifts = CashierShift::whereBetween('opened_at', [$previousStart, $previousEnd])
            ->when($locationName, function($q) use ($locationName) {
                $q->whereHas('cashDrawer', fn($query) => $query->where('location', $locationName));
            })
            ->with('transactions')
            ->get();

        $previousTransactions = $previousShifts->flatMap->transactions;
        if ($currency) {
            $previousTransactions = $previousTransactions->where('currency', $currency);
        }

        $previousRevenue = $previousTransactions->where('type', TransactionType::IN)->sum('amount');

        return [
            'revenue' => $previousRevenue,
            'transactions' => $previousTransactions->count(),
            'revenue_per_shift' => $previousShifts->count() > 0 ? $previousRevenue / $previousShifts->count() : 0,
        ];
    }

    /**
     * Calculate percent change
     */
    protected function calculatePercentChange($current, $previous): float
    {
        if ($previous == 0) return $current > 0 ? 100 : 0;
        return (($current - $previous) / $previous) * 100;
    }

    /**
     * Get top performers by revenue
     */
    protected function getTopPerformers(Carbon $startDate, Carbon $endDate, int $limit = 5): array
    {
        $shifts = CashierShift::whereBetween('opened_at', [$startDate, $endDate])
            ->with(['user', 'transactions'])
            ->get();

        return $shifts->groupBy('user_id')
            ->map(function($group) {
                $user = $group->first()->user;
                $revenue = $group->flatMap->transactions
                    ->where('type', TransactionType::IN)
                    ->sum('amount');

                return [
                    'name' => $user->name,
                    'revenue' => $revenue,
                    'shifts' => $group->count(),
                    'transactions' => $group->flatMap->transactions->count(),
                ];
            })
            ->sortByDesc('revenue')
            ->take($limit)
            ->values()
            ->toArray();
    }

    /**
     * Get system alerts
     */
    protected function getAlerts(): array
    {
        $overdueApprovals = CashierShift::where('status', ShiftStatus::CLOSED)
            ->where('closed_at', '<', now()->subHours(24))
            ->whereNull('approved_at')
            ->whereNull('rejected_at')
            ->count();

        $largeDiscrepancies = CashierShift::where('status', ShiftStatus::CLOSED)
            ->whereHas('endSaldos', fn($q) => $q->whereRaw('ABS(discrepancy) > 10000'))
            ->whereDate('closed_at', '>=', now()->subDays(7))
            ->count();

        return [
            'overdue_approvals' => $overdueApprovals,
            'large_discrepancies' => $largeDiscrepancies,
            'system_anomalies' => 0, // Future feature
        ];
    }

    /**
     * Calculate quality score
     */
    protected function calculateQualityScore(array $discrepancySummary): float
    {
        $accuracyRate = 100 - $discrepancySummary['summary']['discrepancy_rate'];

        // Simple quality score: 70% accuracy, 30% discrepancy amount
        $accuracyScore = $accuracyRate * 0.7;
        $discrepancyPenalty = min(30, $discrepancySummary['summary']['total_discrepancy_amount'] / 1000);

        return max(0, $accuracyScore + 30 - $discrepancyPenalty);
    }
}
