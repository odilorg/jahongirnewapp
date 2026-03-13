<?php

namespace App\Services;

use App\Models\KitchenMealCount;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class KitchenGuestService
{
    protected Beds24BookingService $beds24;
    protected const PROPERTY_ID = 41097;

    public function __construct(Beds24BookingService $beds24)
    {
        $this->beds24 = $beds24;
    }

    /**
     * Get guest count for a specific date.
     * For breakfast: stayovers (arrived before, depart on or after) + arrivals that day
     * Departures ARE included — they haven't left yet at breakfast time.
     *
     * Returns: ['total' => int, 'adults' => int, 'children' => int, 'bookings' => int]
     */
    public function getGuestCountForDate(string $date): array
    {
        $dateStr = $date;

        try {
            // Arrivals for this date
            $arrivalsResp = $this->beds24->getBookings([
                'arrival' => $dateStr,
                'propertyId' => [(string) self::PROPERTY_ID],
            ]);
            $arrivals = $this->filterActive($arrivalsResp['data'] ?? []);

            // Departures for this date (they're still in hotel at breakfast)
            $departuresResp = $this->beds24->getBookings([
                'departure' => $dateStr,
                'propertyId' => [(string) self::PROPERTY_ID],
            ]);
            $departures = $this->filterActive($departuresResp['data'] ?? []);

            // Current bookings (to find stayovers)
            $currentResp = $this->beds24->getBookings([
                'filter' => 'current',
                'propertyId' => [(string) self::PROPERTY_ID],
            ]);
            $currentAll = $currentResp['data'] ?? [];

            // Stayovers = arrived before this date AND depart after this date
            // (not same-day arrivals, not same-day departures — those are separate)
            $stayovers = $this->filterActive(array_filter($currentAll, function ($b) use ($dateStr) {
                return $b['arrival'] < $dateStr && $b['departure'] > $dateStr;
            }));

            // Merge all unique bookings: stayovers + departures + arrivals
            $allBookings = collect(array_merge($stayovers, $departures, $arrivals))
                ->unique('id')
                ->values();

            $adults = $allBookings->sum(fn($b) => (int) ($b['numAdult'] ?? 0));
            $children = $allBookings->sum(fn($b) => (int) ($b['numChild'] ?? 0));
            $total = $adults + $children;

            return [
                'total' => $total,
                'adults' => $adults,
                'children' => $children,
                'bookings' => $allBookings->count(),
                'breakdown' => [
                    'stayovers' => count($stayovers),
                    'departures' => count($departures),
                    'arrivals' => count($arrivals),
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('KitchenGuestService error', ['date' => $date, 'error' => $e->getMessage()]);
            return ['total' => 0, 'adults' => 0, 'children' => 0, 'bookings' => 0, 'breakdown' => []];
        }
    }

    /**
     * Sync today's expected count into kitchen_meal_counts table.
     * Called when bot starts for the day or when user requests fresh data.
     */
    public function syncExpectedCount(string $date, string $mealType = 'breakfast'): KitchenMealCount
    {
        $counts = $this->getGuestCountForDate($date);

        $record = KitchenMealCount::getOrCreate($date, $mealType);
        $record->update([
            'total_expected' => $counts['total'],
            'total_adults' => $counts['adults'],
            'total_children' => $counts['children'],
        ]);

        return $record;
    }

    /**
     * Get a 7-day rolling forecast.
     * Returns array of ['date' => ..., 'total' => ..., 'adults' => ..., 'children' => ...]
     */
    public function getWeeklyForecast(?string $startDate = null): array
    {
        $start = $startDate
            ? Carbon::parse($startDate)
            : now()->timezone('Asia/Tashkent')->startOfDay();

        $forecast = [];
        for ($i = 0; $i < 7; $i++) {
            $date = $start->copy()->addDays($i);
            $dateStr = $date->format('Y-m-d');
            $counts = $this->getGuestCountForDate($dateStr);
            $forecast[] = array_merge($counts, [
                'date' => $dateStr,
                'day_name' => $date->translatedFormat('D'),
                'day_label' => $date->format('d.m'),
            ]);
        }

        return $forecast;
    }

    /**
     * Filter out cancelled/declined bookings and deduplicate by ID.
     */
    protected function filterActive(array $bookings): array
    {
        return array_values(
            collect($bookings)
                ->filter(fn($b) => !in_array($b['status'] ?? '', ['cancelled', 'declined']))
                ->unique('id')
                ->all()
        );
    }
}
