<?php

namespace App\Filament\Widgets;

use App\Enums\TransactionType;
use App\Models\BookingPaymentReconciliation;
use App\Models\CashExpense;
use App\Models\CashTransaction;
use App\Models\ShiftHandover;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

class CashTodayStats extends BaseWidget
{
    protected static ?string $pollingInterval = '30s';

    // Only show on CashDashboard page, not on main Dashboard
    protected static bool $isDiscovered = false;

    protected function getStats(): array
    {
        $today = Carbon::today();

        $incomeUzs = CashTransaction::where('type', 'in')
            ->where('currency', 'UZS')
            ->whereDate('occurred_at', $today)
            ->sum('amount');

        $incomeUsd = CashTransaction::where('type', 'in')
            ->where('currency', 'USD')
            ->whereDate('occurred_at', $today)
            ->sum('amount');

        $expenseUzs = CashTransaction::where('type', 'out')
            ->where('currency', 'UZS')
            ->whereDate('occurred_at', $today)
            ->sum('amount');

        $expenseUsd = CashTransaction::where('type', 'out')
            ->where('currency', 'USD')
            ->whereDate('occurred_at', $today)
            ->sum('amount');

        $pendingApprovals = CashExpense::where('requires_approval', true)
            ->whereNull('approved_at')
            ->whereNull('rejected_at')
            ->count();

        $unresolvedRecon = BookingPaymentReconciliation::whereNull('resolved_at')
            ->where('status', '!=', 'matched')
            ->count();

        $recentDiscrepancies = ShiftHandover::where('created_at', '>=', now()->subDays(7))
            ->get()
            ->filter(fn ($h) => $h->hasDiscrepancy())
            ->count();

        $yesterday = Carbon::yesterday();
        $yesterdayIncomeUzs = CashTransaction::where('type', 'in')
            ->where('currency', 'UZS')
            ->whereDate('occurred_at', $yesterday)
            ->sum('amount');

        return [
            Stat::make('Income Today (UZS)', number_format($incomeUzs) . ' UZS')
                ->description($yesterdayIncomeUzs > 0
                    ? 'Yesterday: ' . number_format($yesterdayIncomeUzs) . ' UZS'
                    : 'No income yesterday')
                ->descriptionIcon('heroicon-o-arrow-trending-up')
                ->color('success'),

            Stat::make('Income Today (USD)', '$' . number_format($incomeUsd, 2))
                ->description('Expenses: $' . number_format($expenseUsd, 2))
                ->descriptionIcon('heroicon-o-currency-dollar')
                ->color('success'),

            Stat::make('Expenses Today (UZS)', number_format($expenseUzs) . ' UZS')
                ->description('Net: ' . number_format($incomeUzs - $expenseUzs) . ' UZS')
                ->descriptionIcon('heroicon-o-minus-circle')
                ->color($expenseUzs > $incomeUzs ? 'danger' : 'warning'),

            Stat::make('Pending Approvals', $pendingApprovals)
                ->description($pendingApprovals > 0 ? 'Action required!' : 'All clear')
                ->descriptionIcon($pendingApprovals > 0 ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-check-circle')
                ->color($pendingApprovals > 0 ? 'danger' : 'success'),

            Stat::make('Reconciliation Issues', $unresolvedRecon)
                ->description($unresolvedRecon > 0 ? 'Unresolved discrepancies' : 'All matched')
                ->descriptionIcon($unresolvedRecon > 0 ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-check-circle')
                ->color($unresolvedRecon > 0 ? 'danger' : 'success'),

            Stat::make('Shift Discrepancies', $recentDiscrepancies)
                ->description('Last 7 days')
                ->descriptionIcon($recentDiscrepancies > 0 ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-check-circle')
                ->color($recentDiscrepancies > 0 ? 'warning' : 'success'),
        ];
    }
}
