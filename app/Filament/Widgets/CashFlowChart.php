<?php

namespace App\Filament\Widgets;

use App\Models\CashTransaction;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class CashFlowChart extends ChartWidget
{
    protected static ?string $heading = 'Cash Flow - Last 30 Days (UZS)';
    protected static ?string $pollingInterval = '60s';
    protected static ?string $maxHeight = '300px';
    protected int|string|array $columnSpan = 'full';

    // Only show on CashDashboard page, not on main Dashboard
    protected static bool $isDiscovered = false;

    protected function getData(): array
    {
        $days = collect();
        $income = collect();
        $expenses = collect();

        for ($i = 29; $i >= 0; $i--) {
            $date = Carbon::today()->subDays($i);
            $days->push($date->format('d M'));

            $dayIncome = CashTransaction::where('type', 'in')
                ->where('currency', 'UZS')
                ->whereDate('occurred_at', $date)
                ->sum('amount');

            $dayExpense = CashTransaction::where('type', 'out')
                ->where('currency', 'UZS')
                ->whereDate('occurred_at', $date)
                ->sum('amount');

            $income->push(round($dayIncome));
            $expenses->push(round($dayExpense));
        }

        return [
            'datasets' => [
                [
                    'label' => 'Income',
                    'data' => $income->toArray(),
                    'borderColor' => '#10b981',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.1)',
                    'fill' => true,
                ],
                [
                    'label' => 'Expenses',
                    'data' => $expenses->toArray(),
                    'borderColor' => '#ef4444',
                    'backgroundColor' => 'rgba(239, 68, 68, 0.1)',
                    'fill' => true,
                ],
            ],
            'labels' => $days->toArray(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
