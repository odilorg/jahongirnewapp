<?php

namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use Flowframe\Trend\Trend;
use Flowframe\Trend\TrendValue;
use App\Models\Expense;
use Carbon\Carbon;

class ExpenseChart extends ChartWidget
{
    protected static ?string $heading = 'Chart';

   
    
    public function getData(): array
    {
        // Expenses for Jahongir hotel
        $jahongirData = Trend::query(
            Expense::where('hotel_id', 1) // Filter for Jahongir hotel
        )
            ->between(
                start: now()->subDays(10)->startOfDay(),
                end: now()->endOfDay(),
            )
            ->perDay()
            ->sum('amount'); // Aggregate total expense per day
    
        // Expenses for Jahongir Premium hotel
        $jahongirPremiumData = Trend::query(
            Expense::where('hotel_id', 2) // Filter for Jahongir Premium hotel
        )
            ->between(
                start: now()->subDays(10)->startOfDay(),
                end: now()->endOfDay(),
            )
            ->perDay()
            ->sum('amount'); // Aggregate total expense per day
    
        return [
            'datasets' => [
                [
                    'label' => 'Jahongir Expenses',
                    'data' => $jahongirData->map(fn (TrendValue $value) => $value->aggregate), // Total expenses for Jahongir
                    'borderColor' => 'rgba(75, 192, 192, 1)', // Color for Jahongir
                ],
                [
                    'label' => 'Jahongir Premium Expenses',
                    'data' => $jahongirPremiumData->map(fn (TrendValue $value) => $value->aggregate), // Total expenses for Jahongir Premium
                    'borderColor' => 'rgba(255, 99, 132, 1)', // Color for Jahongir Premium
                ],
            ],
            'labels' => $jahongirData->map(fn (TrendValue $value) => Carbon::parse($value->date)->format('Y-m-d')), // Shared labels
        ];
    }
    



    protected function getType(): string
    {
        return 'line';
    }
}
