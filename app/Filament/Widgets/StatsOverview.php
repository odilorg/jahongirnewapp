<?php

namespace App\Filament\Widgets;

use App\Models\Zayavka;
use App\Models\Contract;
use App\Models\UtilityUsage;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;

class StatsOverview extends BaseWidget
{
    protected function getStats(): array
{
    // Count Zayavka for the past 30 days
    $zayavkaCount = Zayavka::where('created_at', '>=', now()->subDays(30))->count();

    // Sum meter_difference for both hotels for the past month
    $meterDifferenceSum = UtilityUsage::whereBetween('usage_date', [
        now()->subMonth()->startOfMonth(), // Start of the past month
        now()->subMonth()->endOfMonth(),   // End of the past month
    ])
    ->where('utility_id', 1) // Filter by utility_id = 3
    ->sum('meter_difference');

    // Count Contracts for both hotels for the past 10 days
    $contractCount = Contract::where('created_at', '>=', now()->subDays(10))->count();

    return [
        Stat::make('Zayavka (Past 30 Days)', $zayavkaCount),
        Stat::make('Tabiiy Gaz Jahongir', $meterDifferenceSum),
        Stat::make('Contracts (Past 10 Days)', $contractCount),
    ];
}
}
