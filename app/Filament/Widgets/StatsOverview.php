<?php

namespace App\Filament\Widgets;

use App\Models\Contract;
use App\Models\Hotel;
use App\Models\Utility;
use App\Models\UtilityUsage;
use App\Models\Zayavka;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Admin dashboard tiles.
 *
 * The natural-gas tile previously hard-coded `where('utility_id', 1)` and
 * also summed across BOTH hotels while labeling itself "Jahongir" — two
 * separate sources of misleading numbers. The widget now resolves the
 * utility by name and emits one tile per hotel so the labels match the
 * data.
 */
class StatsOverview extends BaseWidget
{
    /** Utility we expose on the dashboard. Resolved by name to survive id reordering. */
    private const HEADLINE_UTILITY = 'Tabiyy gaz';

    protected function getStats(): array
    {
        return array_merge(
            [Stat::make('Zayavka (Past 30 Days)', Zayavka::where('created_at', '>=', now()->subDays(30))->count())],
            $this->gasUsageTilesPerHotel(),
            [Stat::make('Contracts (Past 10 Days)', Contract::where('created_at', '>=', now()->subDays(10))->count())],
        );
    }

    /**
     * One natural-gas usage tile per hotel for the previous calendar
     * month. Uses Utility lookup by name, never a fragile literal id.
     *
     * @return array<int, Stat>
     */
    private function gasUsageTilesPerHotel(): array
    {
        $utility = Utility::firstWhere('name', self::HEADLINE_UTILITY);
        if (! $utility) {
            return [Stat::make('Tabiiy Gaz', '—')->description('Utility not configured')];
        }

        $start = now()->subMonth()->startOfMonth();
        $end   = now()->subMonth()->endOfMonth();

        return Hotel::orderBy('id')->get()
            ->map(function (Hotel $hotel) use ($utility, $start, $end) {
                $sum = UtilityUsage::query()
                    ->whereBetween('usage_date', [$start, $end])
                    ->where('utility_id', $utility->id)
                    ->where('hotel_id', $hotel->id)
                    ->sum('meter_difference');

                return Stat::make("Tabiiy Gaz — {$hotel->name}", (int) $sum);
            })
            ->all();
    }
}
