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
     * Get breakfast guest count for a specific date.
     *
     * Breakfast = people who SLEPT in the hotel last night:
     *   - Stayovers: arrived before this date, depart after this date
     *   - Departures: leaving today (slept last night, eat breakfast before checkout)
     *
     * Arrivals are NOT included — they check in later in the day.
     * Arrivals are returned in breakdown for informational display only.
     *
     * Returns: ['total' => int, 'adults' => int, 'children' => int, 'bookings' => int]
     */
    public function getGuestCountForDate(string $date): array
    {
        $dateStr = $date;

        try {
            // Arrivals for this date (NOT counted for breakfast — informational only)
            $arrivalsResp = $this->beds24->getBookings([
                'arrival' => $dateStr,
                'propertyId' => [(string) self::PROPERTY_ID],
            ]);
            $arrivals = $this->filterActive($arrivalsResp['data'] ?? []);

            // Departures for this date (they slept last night → eat breakfast)
            $departuresResp = $this->beds24->getBookings([
                'departure' => $dateStr,
                'propertyId' => [(string) self::PROPERTY_ID],
            ]);
            $departures = $this->filterActive($departuresResp['data'] ?? []);

            // Breakfast guests = everyone sleeping the night BEFORE this date
            // Arrived on or before yesterday, departs on or after today
            $prevDay = \Carbon\Carbon::parse($dateStr)->subDay()->format('Y-m-d');
            $overnightResp = $this->beds24->getBookings([
                'arrivalFrom' => '2020-01-01',
                'arrivalTo' => $prevDay,
                'departureFrom' => $dateStr,
                'propertyId' => [(string) self::PROPERTY_ID],
            ]);
            $overnightAll = $this->filterActive($overnightResp['data'] ?? []);

            // Breakfast guests = everyone who slept the previous night
            $breakfastGuests = collect($overnightAll)
                ->unique('id')
                ->values();

            // Stayovers for breakdown = those continuing past today
            $stayovers = $breakfastGuests->filter(fn($b) => $b['departure'] > $dateStr)->values()->all();

            $adults = $breakfastGuests->sum(fn($b) => (int) ($b['numAdult'] ?? 0));
            $children = $breakfastGuests->sum(fn($b) => (int) ($b['numChild'] ?? 0));
            $total = $adults + $children;

            return [
                'total' => $total,
                'adults' => $adults,
                'children' => $children,
                'bookings' => $breakfastGuests->count(),
                'breakdown' => [
                    'stayovers' => count($stayovers),
                    'departures' => count($departures),
                    'arrivals' => count($arrivals), // info only, not in total
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
