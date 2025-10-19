# Phase 1 Implementation Guide - Week 1 Priority Reports

## Overview

This guide provides step-by-step implementation instructions for the 4 highest-priority reports to be delivered in Week 1.

**Reports:**
1. Date Range Financial Summary (4h)
2. Discrepancy & Variance Report (4h)
3. Executive Summary Dashboard (8h)
4. Currency Exchange Report (6h)

**Total:** 22 hours (~3 working days)

---

## Architecture Overview

### File Structure
```
app/
├── Services/
│   ├── TelegramReportService.php (existing - add methods)
│   ├── AdvancedReportService.php (NEW)
│   └── TelegramReportFormatter.php (existing - add methods)
├── Http/
│   └── Controllers/
│       ├── TelegramPosController.php (existing - update)
│       └── Api/
│           └── ReportController.php (NEW - for web API)
└── Enums/
    └── ReportPeriod.php (NEW)

database/migrations/
└── 2025_10_19_add_report_indexes.php (NEW)

tests/
└── Feature/
    └── ReportsTest.php (NEW)
```

---

## Step 1: Database Optimization (30 min)

### Create Migration
```bash
php artisan make:migration add_report_performance_indexes
```

### Migration Content
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddReportPerformanceIndexes extends Migration
{
    public function up()
    {
        // Optimize date range queries
        Schema::table('cashier_shifts', function (Blueprint $table) {
            $table->index('opened_at', 'idx_cashier_shifts_opened_at');
            $table->index(['status', 'opened_at'], 'idx_cashier_shifts_status_opened');
            $table->index('closed_at', 'idx_cashier_shifts_closed_at');
        });

        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->index('occurred_at', 'idx_cash_transactions_occurred_at');
            $table->index(['cashier_shift_id', 'occurred_at'], 'idx_cash_transactions_shift_occurred');
            $table->index(['currency', 'occurred_at'], 'idx_cash_transactions_currency_occurred');
            $table->index('type', 'idx_cash_transactions_type');
        });
    }

    public function down()
    {
        Schema::table('cashier_shifts', function (Blueprint $table) {
            $table->dropIndex('idx_cashier_shifts_opened_at');
            $table->dropIndex('idx_cashier_shifts_status_opened');
            $table->dropIndex('idx_cashier_shifts_closed_at');
        });

        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->dropIndex('idx_cash_transactions_occurred_at');
            $table->dropIndex('idx_cash_transactions_shift_occurred');
            $table->dropIndex('idx_cash_transactions_currency_occurred');
            $table->dropIndex('idx_cash_transactions_type');
        });
    }
}
```

**Run Migration:**
```bash
php artisan migrate
```

---

## Step 2: Create ReportPeriod Enum (15 min)

```php
<?php

namespace App\Enums;

enum ReportPeriod: string
{
    case TODAY = 'today';
    case YESTERDAY = 'yesterday';
    case THIS_WEEK = 'this_week';
    case LAST_WEEK = 'last_week';
    case THIS_MONTH = 'this_month';
    case LAST_MONTH = 'last_month';
    case THIS_QUARTER = 'this_quarter';
    case LAST_QUARTER = 'last_quarter';
    case THIS_YEAR = 'this_year';
    case LAST_YEAR = 'last_year';
    case CUSTOM = 'custom';

    public function getDateRange(): array
    {
        return match($this) {
            self::TODAY => [
                Carbon::today(),
                Carbon::today()->endOfDay()
            ],
            self::YESTERDAY => [
                Carbon::yesterday(),
                Carbon::yesterday()->endOfDay()
            ],
            self::THIS_WEEK => [
                Carbon::now()->startOfWeek(),
                Carbon::now()->endOfWeek()
            ],
            self::LAST_WEEK => [
                Carbon::now()->subWeek()->startOfWeek(),
                Carbon::now()->subWeek()->endOfWeek()
            ],
            self::THIS_MONTH => [
                Carbon::now()->startOfMonth(),
                Carbon::now()->endOfMonth()
            ],
            self::LAST_MONTH => [
                Carbon::now()->subMonth()->startOfMonth(),
                Carbon::now()->subMonth()->endOfMonth()
            ],
            self::THIS_QUARTER => [
                Carbon::now()->startOfQuarter(),
                Carbon::now()->endOfQuarter()
            ],
            self::LAST_QUARTER => [
                Carbon::now()->subQuarter()->startOfQuarter(),
                Carbon::now()->subQuarter()->endOfQuarter()
            ],
            self::THIS_YEAR => [
                Carbon::now()->startOfYear(),
                Carbon::now()->endOfYear()
            ],
            self::LAST_YEAR => [
                Carbon::now()->subYear()->startOfYear(),
                Carbon::now()->subYear()->endOfYear()
            ],
            self::CUSTOM => throw new \Exception('Custom period requires explicit dates')
        };
    }
}
```

---

## Step 3: Create AdvancedReportService (6 hours)

```php
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
        $shifts = $this->getScopedShiftsQuery($manager)
            ->whereBetween('opened_at', [$startDate, $endDate])
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
        $previousPeriod = $this->getPreviousPeriodMetrics($manager, $startDate, $endDate, $locationName, $currency);

        return [
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'days' => $dayCount,
            ],
            'location' => $locationName ?? 'All Locations',
            'currency' => $currency?->value ?? 'All Currencies',

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
        $shifts = $this->getScopedShiftsQuery($manager)
            ->whereBetween('opened_at', [$startDate, $endDate])
            ->when($locationName, function($q) use ($locationName) {
                $q->whereHas('cashDrawer', fn($query) => $query->where('location', $locationName));
            })
            ->with(['transactions' => fn($q) => $q->where('type', TransactionType::IN_OUT)])
            ->get();

        $exchanges = $shifts->flatMap->transactions;

        // Group by currency to find common pairs
        $exchangesByCurrency = $exchanges->groupBy('currency');

        // Calculate exchange pairs (heuristic: group by time proximity)
        $exchangePairs = [];
        foreach ($exchanges as $exchange) {
            $pair = $this->findExchangePair($exchange, $exchanges);
            if ($pair) {
                $pairKey = $this->getCurrencyPairKey($exchange->currency, $pair->currency);
                if (!isset($exchangePairs[$pairKey])) {
                    $exchangePairs[$pairKey] = [
                        'from' => $exchange->currency->value,
                        'to' => $pair->currency->value,
                        'count' => 0,
                        'total_from_amount' => 0,
                        'total_to_amount' => 0,
                    ];
                }
                $exchangePairs[$pairKey]['count']++;
                $exchangePairs[$pairKey]['total_from_amount'] += $exchange->amount;
                $exchangePairs[$pairKey]['total_to_amount'] += $pair->amount;
            }
        }

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
                'avg_exchange_amount' => $exchanges->avg('amount'),
            ],

            'currency_pairs' => array_values($exchangePairs),

            'by_currency' => $exchangesByCurrency->map(fn($group, $currency) => [
                'currency' => $currency,
                'count' => $group->count(),
                'total_amount' => $group->sum('amount'),
                'avg_amount' => $group->avg('amount'),
            ])->values(),

            'hourly_pattern' => $exchangesByHour,

            'largest_exchanges' => $exchanges->sortByDesc('amount')->take(10)->map(fn($e) => [
                'amount' => $e->amount,
                'currency' => $e->currency->value,
                'occurred_at' => $e->occurred_at,
                'shift_id' => $e->cashier_shift_id,
                'cashier' => $e->shift->user->name,
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

        // Get all closed shifts with discrepancies
        $shifts = $this->getScopedShiftsQuery($manager)
            ->whereBetween('opened_at', [$startDate, $endDate])
            ->where('status', ShiftStatus::CLOSED)
            ->when($locationName, function($q) use ($locationName) {
                $q->whereHas('cashDrawer', fn($query) => $query->where('location', $locationName));
            })
            ->with(['user', 'cashDrawer', 'endSaldos'])
            ->get();

        // Find shifts with discrepancies using endSaldos
        $shiftsWithDiscrepancies = $shifts->filter(function($shift) use ($minDiscrepancyAmount) {
            if (!$shift->endSaldos) return false;

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
                foreach ($shift->endSaldos ?? [] as $endSaldo) {
                    $totalDiscrepancy += abs($endSaldo->discrepancy ?? 0);
                }
            }

            return [
                'cashier_id' => $cashier->id,
                'cashier_name' => $cashier->name,
                'total_shifts' => $totalShifts,
                'discrepancy_shifts' => $discrepancyShifts,
                'accuracy_rate' => $totalShifts > 0 ? ($totalShifts - $discrepancyShifts) / $totalShifts * 100 : 0,
                'total_discrepancy_amount' => $totalDiscrepancy,
                'avg_discrepancy_amount' => $discrepancyShifts > 0 ? $totalDiscrepancy / $discrepancyShifts : 0,
            ];
        })->sortByDesc('total_discrepancy_amount')->values();

        // Top 10 largest discrepancies
        $topDiscrepancies = [];
        foreach ($shiftsWithDiscrepancies as $shift) {
            foreach ($shift->endSaldos ?? [] as $endSaldo) {
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
            $startDate = $customStartDate;
            $endDate = $customEndDate;
        } else {
            [$startDate, $endDate] = $period->getDateRange();
        }

        // Get financial summary
        $financialSummary = $this->getDateRangeFinancialSummary($manager, $startDate, $endDate);

        // Get discrepancy summary
        $discrepancySummary = $this->getDiscrepancyVarianceReport($manager, $startDate, $endDate);

        // Get active shifts
        $activeShifts = CashierShift::where('status', ShiftStatus::OPEN)->count();

        // Get top performers (by revenue)
        $topPerformers = $this->getTopPerformers($manager, $startDate, $endDate, 5);

        // Get alerts
        $alerts = $this->getAlerts($manager);

        return [
            'period' => [
                'label' => $period->value,
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

    protected function isManagerAuthorized(User $user): bool
    {
        return $user->hasAnyRole(['manager', 'super_admin']);
    }

    protected function getScopedShiftsQuery(User $manager)
    {
        return CashierShift::query();
    }

    protected function getPreviousPeriodMetrics($manager, $startDate, $endDate, $locationName, $currency)
    {
        $duration = $startDate->diffInDays($endDate);
        $previousStart = $startDate->copy()->subDays($duration + 1);
        $previousEnd = $endDate->copy()->subDays($duration + 1);

        $previousData = $this->getDateRangeFinancialSummary($manager, $previousStart, $previousEnd, $locationName, $currency);

        return [
            'revenue' => $previousData['summary']['revenue'] ?? 0,
            'transactions' => $previousData['transactions']['total'] ?? 0,
            'revenue_per_shift' => $previousData['daily_averages']['revenue_per_shift'] ?? 0,
        ];
    }

    protected function calculatePercentChange($current, $previous)
    {
        if ($previous == 0) return $current > 0 ? 100 : 0;
        return (($current - $previous) / $previous) * 100;
    }

    protected function findExchangePair($exchange, $allExchanges)
    {
        // Find exchange in opposite direction within 5 minutes
        return $allExchanges->first(function($e) use ($exchange) {
            return $e->id !== $exchange->id
                && $e->currency !== $exchange->currency
                && abs($e->occurred_at->diffInMinutes($exchange->occurred_at)) <= 5;
        });
    }

    protected function getCurrencyPairKey($curr1, $curr2)
    {
        return $curr1->value < $curr2->value ? "{$curr1->value}_{$curr2->value}" : "{$curr2->value}_{$curr1->value}";
    }

    protected function getTopPerformers($manager, $startDate, $endDate, $limit = 5)
    {
        $shifts = $this->getScopedShiftsQuery($manager)
            ->whereBetween('opened_at', [$startDate, $endDate])
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
            ->values();
    }

    protected function getAlerts($manager)
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

    protected function calculateQualityScore($discrepancySummary)
    {
        $accuracyRate = 100 - $discrepancySummary['summary']['discrepancy_rate'];

        // Simple quality score: 70% accuracy, 30% discrepancy amount
        $accuracyScore = $accuracyRate * 0.7;
        $discrepancyPenalty = min(30, $discrepancySummary['summary']['total_discrepancy_amount'] / 1000);

        return max(0, $accuracyScore + 30 - $discrepancyPenalty);
    }
}
```

---

## Step 4: Add Telegram Bot Integration (4 hours)

Update `TelegramPosController.php`:

```php
// Add to existing report callback handler

protected function handleReportCallback($session, string $callbackData, int $chatId)
{
    $lang = $session->language ?? 'en';
    $user = $session->user;

    if (!$user->hasAnyRole(['manager', 'super_admin'])) {
        $this->sendMessage($chatId, __('telegram_pos.manager_only', [], $lang));
        return response('OK');
    }

    $reportType = substr($callbackData, 7); // Remove 'report:'

    switch ($reportType) {
        // ... existing cases ...

        case 'financial_range':
            return $this->handleFinancialRangeReport($chatId, $user, $lang);
        case 'discrepancies':
            return $this->handleDiscrepanciesReport($chatId, $user, $lang);
        case 'executive':
            return $this->handleExecutiveDashboard($chatId, $user, $lang);
        case 'currency_exchange':
            return $this->handleCurrencyExchangeReport($chatId, $user, $lang);
    }

    return response('OK');
}

protected function handleFinancialRangeReport(int $chatId, User $user, string $lang)
{
    $service = app(AdvancedReportService::class);

    // Default to this month
    $data = $service->getDateRangeFinancialSummary(
        $user,
        Carbon::now()->startOfMonth(),
        Carbon::now()->endOfMonth()
    );

    if (isset($data['error'])) {
        $this->sendMessage($chatId, "❌ " . $data['error']);
        return response('OK');
    }

    $formatter = app(TelegramReportFormatter::class);
    $message = $formatter->formatFinancialRangeSummary($data, $lang);
    $this->sendMessage($chatId, $message);

    return response('OK');
}

// Similar methods for other reports...
```

---

## Step 5: Create Report Formatters (4 hours)

Add to `TelegramReportFormatter.php`:

```php
public function formatFinancialRangeSummary(array $data, string $lang): string
{
    $period = $data['period']['start_date']->format('M d') . ' - ' . $data['period']['end_date']->format('M d, Y');

    $message = "📊 <b>FINANCIAL SUMMARY</b>\n";
    $message .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    $message .= "📅 Period: {$period} ({$data['period']['days']} days)\n";
    $message .= "📍 Location: {$data['location']}\n\n";

    $message .= "💰 <b>REVENUE</b>\n";
    $message .= "   Total: " . number_format($data['summary']['revenue'], 0) . " UZS\n";
    $message .= "   Per Day: " . number_format($data['daily_averages']['revenue_per_day'], 0) . " UZS\n";
    $message .= "   Per Shift: " . number_format($data['daily_averages']['revenue_per_shift'], 0) . " UZS\n";

    if ($data['comparison']['revenue_change_pct'] != 0) {
        $arrow = $data['comparison']['revenue_change_pct'] > 0 ? '↗️' : '↘️';
        $sign = $data['comparison']['revenue_change_pct'] > 0 ? '+' : '';
        $message .= "   Change: {$arrow} {$sign}" . number_format($data['comparison']['revenue_change_pct'], 1) . "%\n";
    }

    $message .= "\n💸 <b>EXPENSES</b>\n";
    $message .= "   Total: " . number_format($data['summary']['expenses'], 0) . " UZS\n\n";

    $message .= "📈 <b>NET CASH FLOW</b>\n";
    $message .= "   " . number_format($data['summary']['net_cash_flow'], 0) . " UZS\n\n";

    $message .= "📊 <b>TRANSACTIONS</b>\n";
    $message .= "   Total: {$data['transactions']['total']}\n";
    $message .= "   Cash In: {$data['transactions']['cash_in']}\n";
    $message .= "   Cash Out: {$data['transactions']['cash_out']}\n";
    $message .= "   Exchanges: {$data['transactions']['exchanges']}\n\n";

    if (!empty($data['currency_breakdown'])) {
        $message .= "💱 <b>BY CURRENCY</b>\n";
        foreach ($data['currency_breakdown'] as $currency => $amounts) {
            $message .= "   {$currency}:\n";
            $message .= "      Revenue: " . number_format($amounts['revenue'], 0) . "\n";
            $message .= "      Expenses: " . number_format($amounts['expenses'], 0) . "\n";
            $message .= "      Net: " . number_format($amounts['net'], 0) . "\n";
        }
    }

    return $message;
}

public function formatDiscrepancyReport(array $data, string $lang): string
{
    $message = "⚠️ <b>DISCREPANCY REPORT</b>\n";
    $message .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

    $message .= "📅 Period: " . $data['period']['start_date']->format('M d') . " - " . $data['period']['end_date']->format('M d, Y') . "\n\n";

    $accuracyRate = 100 - $data['summary']['discrepancy_rate'];
    $icon = $accuracyRate >= 97 ? '✅' : ($accuracyRate >= 90 ? '⚠️' : '❌');

    $message .= "🎯 <b>ACCURACY RATE</b>\n";
    $message .= "   {$icon} " . number_format($accuracyRate, 1) . "%\n\n";

    $message .= "📊 <b>OVERVIEW</b>\n";
    $message .= "   Total Shifts: {$data['summary']['total_shifts']}\n";
    $message .= "   With Discrepancies: {$data['summary']['shifts_with_discrepancies']}\n";
    $message .= "   Total Amount: " . number_format($data['summary']['total_discrepancy_amount'], 0) . " UZS\n\n";

    if (!empty($data['by_cashier']) && count($data['by_cashier']) > 0) {
        $message .= "👥 <b>BY CASHIER (Top 5)</b>\n";
        foreach (array_slice($data['by_cashier'], 0, 5) as $cashier) {
            $accuracy = number_format($cashier['accuracy_rate'], 1);
            $icon = $cashier['accuracy_rate'] >= 95 ? '🟢' : ($cashier['accuracy_rate'] >= 90 ? '🟡' : '🔴');

            $message .= "   {$icon} {$cashier['cashier_name']}\n";
            $message .= "      Accuracy: {$accuracy}%\n";
            $message .= "      Issues: {$cashier['discrepancy_shifts']}/{$cashier['total_shifts']}\n";
            $message .= "      Amount: " . number_format($cashier['total_discrepancy_amount'], 0) . " UZS\n\n";
        }
    }

    return $message;
}

// Add similar formatters for other reports...
```

---

## Step 6: Update Keyboard Builder (30 min)

Update `TelegramKeyboardBuilder.php`:

```php
public function managerReportsKeyboard(string $language = 'en'): array
{
    return [
        'inline_keyboard' => [
            [
                ['text' => __('telegram_pos.today_summary', [], $language), 'callback_data' => 'report:today'],
            ],
            [
                ['text' => '💰 Financial Summary', 'callback_data' => 'report:financial_range'],
            ],
            [
                ['text' => '⚠️ Discrepancies', 'callback_data' => 'report:discrepancies'],
            ],
            [
                ['text' => '💱 Currency Exchange', 'callback_data' => 'report:currency_exchange'],
            ],
            [
                ['text' => '🎯 Executive Dashboard', 'callback_data' => 'report:executive'],
            ],
            [
                ['text' => __('telegram_pos.back', [], $language), 'callback_data' => 'report:back'],
            ],
        ],
    ];
}
```

---

## Step 7: Testing (2 hours)

Create `tests/Feature/AdvancedReportsTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\CashierShift;
use App\Models\CashTransaction;
use App\Services\AdvancedReportService;
use App\Enums\ShiftStatus;
use App\Enums\TransactionType;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AdvancedReportsTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;
    protected AdvancedReportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create();
        $this->manager->assignRole('manager');

        $this->service = app(AdvancedReportService::class);
    }

    /** @test */
    public function it_generates_date_range_financial_summary()
    {
        // Create test data
        $shift = CashierShift::factory()->create([
            'opened_at' => Carbon::today(),
            'status' => ShiftStatus::CLOSED,
        ]);

        CashTransaction::factory()->count(5)->create([
            'cashier_shift_id' => $shift->id,
            'type' => TransactionType::IN,
            'amount' => 1000,
        ]);

        // Generate report
        $report = $this->service->getDateRangeFinancialSummary(
            $this->manager,
            Carbon::today(),
            Carbon::today()
        );

        // Assertions
        $this->assertArrayHasKey('summary', $report);
        $this->assertEquals(5000, $report['summary']['revenue']);
        $this->assertEquals(5, $report['transactions']['total']);
    }

    /** @test */
    public function it_generates_discrepancy_report()
    {
        // Create shift with discrepancy
        $shift = CashierShift::factory()->create([
            'opened_at' => Carbon::today(),
            'status' => ShiftStatus::CLOSED,
            'discrepancy' => 500,
        ]);

        // Generate report
        $report = $this->service->getDiscrepancyVarianceReport(
            $this->manager,
            Carbon::today(),
            Carbon::today()
        );

        // Assertions
        $this->assertGreaterThan(0, $report['summary']['shifts_with_discrepancies']);
    }

    // Add more tests...
}
```

Run tests:
```bash
php artisan test --filter=AdvancedReportsTest
```

---

## Step 8: Deployment Checklist

### Pre-Deployment
- [ ] Run migrations
- [ ] Run tests (all passing)
- [ ] Code review completed
- [ ] Update documentation

### Deployment
```bash
# On server
cd /var/www/jahongirnewapp

# Pull changes
git pull origin feature/manager-reports-clean

# Run migrations
php artisan migrate

# Clear caches
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

# Optimize
php artisan optimize
php artisan config:cache
php artisan route:cache

# Restart services
sudo systemctl restart php8.2-fpm
sudo systemctl reload nginx
```

### Post-Deployment Testing
```bash
# Test Telegram webhook
curl -X POST https://jahongir-app.uz/api/telegram/pos/webhook \
  -H "Content-Type: application/json" \
  -d '{"message":{"chat":{"id":38738713},"text":"📊 Reports"}}'

# Check logs
tail -f storage/logs/laravel.log
```

---

## Success Criteria

✅ **Week 1 Complete When:**
1. All 4 reports working in Telegram bot
2. Reports return accurate data
3. No performance issues (<2s response time)
4. All tests passing
5. Documentation updated
6. Manager team trained

---

## Timeline

| Task | Duration | Status |
|------|----------|--------|
| Database indexes | 30 min | ⏳ |
| ReportPeriod enum | 15 min | ⏳ |
| AdvancedReportService | 6 hours | ⏳ |
| Telegram integration | 4 hours | ⏳ |
| Report formatters | 4 hours | ⏳ |
| Keyboard updates | 30 min | ⏳ |
| Testing | 2 hours | ⏳ |
| Deployment | 1 hour | ⏳ |
| **TOTAL** | **~22 hours** | **⏳** |

---

## Support & Troubleshooting

### Common Issues

**Issue: Slow report generation**
```php
// Enable query logging to find slow queries
DB::enableQueryLog();
$report = $service->getDateRangeFinancialSummary(...);
dd(DB::getQueryLog());
```

**Issue: Memory limit on large date ranges**
```php
// Add chunking for large datasets
$shifts->chunk(100, function($chunk) {
    // Process chunk
});
```

**Issue: Telegram timeout (>30s)**
```php
// Queue long-running reports
dispatch(new GenerateReportJob($userId, $reportType, $params));
```

---

## Next: Week 2 Planning

After Week 1 completion, prepare for Phase 2 reports:
- Cashier Performance Scorecard
- Location Performance Comparison
- Peak Hours Analysis
- Revenue Trend Analysis

**Estimated Start:** After Week 1 deployment + 1 day buffer
