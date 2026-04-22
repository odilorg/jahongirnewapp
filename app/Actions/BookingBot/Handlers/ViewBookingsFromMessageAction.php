<?php

declare(strict_types=1);

namespace App\Actions\BookingBot\Handlers;

use App\Models\RoomUnitMapping;
use App\Services\Beds24BookingService;
use App\Services\BookingBot\BookingListFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Handles "view bookings" intent from @j_booking_hotel_bot.
 *
 * Serves:
 *   - inline-button callbacks (view_arrivals_today, view_departures_today,
 *     view_current, view_new) via filter_type
 *   - typed queries: "bookings today", "arrivals may 5-10",
 *     "departures next week", "bookings on may 5", "bookings may 5-7"
 *
 * Phase 9 rules (see memory project_hotel_bot_charges):
 *   A. default semantics — stays overlap [from, to]
 *      (arrival <= to AND departure >= from)
 *   B. single date = 1-day range
 *   C. max range width — 31 days (config)
 *   E. combinable: filter_type (arrivals/departures/new) + dates
 *   F. group/sort by relevant date (arrival/departure depending on mode)
 *   G. empty state: "No bookings found for {title}."
 *   H. month without year → next occurrence (parser responsibility)
 *   I. property hint narrows the filter when supplied
 */
final class ViewBookingsFromMessageAction
{
    public function __construct(
        private readonly Beds24BookingService $beds24,
        private readonly BookingListFormatter $formatter,
    ) {}

    public function execute(array $parsed): string
    {
        try {
            $rooms = RoomUnitMapping::all();

            $plan = $this->buildQueryPlan($parsed, $rooms);
            if (is_string($plan)) {
                return $plan; // early exit (validation error)
            }

            Log::info('Fetching bookings', [
                'filters' => $plan['filters'],
                'title'   => $plan['title'],
                'mode'    => $plan['mode'],
            ]);

            $result = $this->beds24->getBookings($plan['filters']);

            Log::info('Bookings result', [
                'success' => $result['success'] ?? false,
                'count'   => $result['count'] ?? 0,
            ]);

            $bookings = $result['data'] ?? [];

            return $this->formatter->format(
                bookings: is_array($bookings) ? $bookings : [],
                title:    $plan['title'],
                rooms:    $rooms,
                mode:     $plan['mode'],
            );

        } catch (\Exception $e) {
            Log::error('View bookings failed', [
                'error'  => $e->getMessage(),
                'parsed' => $parsed,
            ]);

            return 'Error fetching bookings: ' . $e->getMessage() .
                   "\n\nPlease try again or contact support.";
        }
    }

    /**
     * Resolve the query plan from parsed intent. Returns either an array
     * {filters, title, mode} or a string (operator-facing early response,
     * e.g. range-cap rejection).
     *
     * @return array{filters: array<string, mixed>, title: string, mode: string}|string
     */
    private function buildQueryPlan(array $parsed, $rooms): array|string
    {
        $filters     = [];
        $filterType  = $parsed['filter_type'] ?? null;
        $propertyIds = $rooms->pluck('property_id')->unique()->values()->toArray();
        $filters['propertyId'] = $propertyIds;

        $dates = $parsed['dates'] ?? null;
        [$from, $to] = $this->resolveDateRange($dates);

        // Range-width cap (Rule C). Only applies when a range is present.
        if ($from !== null && $to !== null) {
            $widthDays = (int) CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1;
            $cap = (int) config('hotel_booking_bot.view.max_range_days', 31);
            if ($widthDays > $cap) {
                return "Range too large (max {$cap} days, got {$widthDays}). Please narrow your request.";
            }
        }

        // Shortcut handling: "today".
        if ($filterType === 'today' || (is_string($filterType) && strtolower($filterType) === 'today')) {
            $today = date('Y-m-d');
            $from = $from ?? $today;
            $to   = $to ?? $today;
            $filterType = null; // route to default stays-overlap
        }

        // Search string path (unchanged).
        if (isset($parsed['search_string']) && $parsed['search_string'] !== '') {
            $filters['searchString'] = (string) $parsed['search_string'];
            return [
                'filters' => $filters,
                'title'   => 'Search: ' . $parsed['search_string'],
                'mode'    => BookingListFormatter::MODE_NONE,
            ];
        }

        switch ($filterType) {
            case 'arrivals_today':
                $today = date('Y-m-d');
                $filters['arrivalFrom'] = $today;
                $filters['arrivalTo']   = $today;
                return [
                    'filters' => $filters,
                    'title'   => 'Arrivals Today (' . date('M j, Y') . ')',
                    'mode'    => BookingListFormatter::MODE_ARRIVALS,
                ];

            case 'departures_today':
                $today = date('Y-m-d');
                $filters['departureFrom'] = $today;
                $filters['departureTo']   = $today;
                return [
                    'filters' => $filters,
                    'title'   => 'Departures Today (' . date('M j, Y') . ')',
                    'mode'    => BookingListFormatter::MODE_DEPARTURES,
                ];

            case 'arrivals':
                if ($from === null || $to === null) {
                    return 'Please provide a date or range for arrivals. Example: arrivals may 5-10';
                }
                $filters['arrivalFrom'] = $from;
                $filters['arrivalTo']   = $to;
                return [
                    'filters' => $filters,
                    'title'   => 'Arrivals ' . $this->rangeTitle($from, $to),
                    'mode'    => BookingListFormatter::MODE_ARRIVALS,
                ];

            case 'departures':
                if ($from === null || $to === null) {
                    return 'Please provide a date or range for departures. Example: departures may 5-10';
                }
                $filters['departureFrom'] = $from;
                $filters['departureTo']   = $to;
                return [
                    'filters' => $filters,
                    'title'   => 'Departures ' . $this->rangeTitle($from, $to),
                    'mode'    => BookingListFormatter::MODE_DEPARTURES,
                ];

            case 'current':
                $today = date('Y-m-d');
                $filters['arrivalTo']     = $today;
                $filters['departureFrom'] = date('Y-m-d', strtotime('+1 day'));
                return [
                    'filters' => $filters,
                    'title'   => 'Current Bookings (In-House)',
                    'mode'    => BookingListFormatter::MODE_STAYS,
                ];

            case 'new':
                $filters['status'] = ['new', 'request'];
                if ($from !== null && $to !== null) {
                    // Stays overlap semantics when a range is supplied.
                    $filters['arrivalTo']     = $to;
                    $filters['departureFrom'] = $from;
                }
                return [
                    'filters' => $filters,
                    'title'   => 'New Bookings' .
                        (($from && $to) ? ' — ' . $this->rangeTitle($from, $to) : ' (Unconfirmed)'),
                    'mode'    => BookingListFormatter::MODE_NONE,
                ];

            default:
                // No filter_type: stays-overlap semantics when a range is
                // supplied, otherwise "upcoming from today" (legacy).
                if ($from !== null && $to !== null) {
                    $filters['arrivalTo']     = $to;
                    $filters['departureFrom'] = $from;
                    return [
                        'filters' => $filters,
                        'title'   => 'Bookings ' . $this->rangeTitle($from, $to),
                        'mode'    => BookingListFormatter::MODE_STAYS,
                    ];
                }

                $filters['arrivalFrom'] = date('Y-m-d');
                return [
                    'filters' => $filters,
                    'title'   => 'Upcoming Bookings',
                    'mode'    => BookingListFormatter::MODE_ARRIVALS,
                ];
        }
    }

    /**
     * Resolve $parsed['dates'] into a normalized [from, to] pair.
     * A single date (check_in without check_out, or equal) becomes a
     * one-day range (Rule B).
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveDateRange(?array $dates): array
    {
        if (! $dates) {
            return [null, null];
        }

        $in  = $this->normalizeYmd($dates['check_in']  ?? null);
        $out = $this->normalizeYmd($dates['check_out'] ?? null);

        if ($in !== null && $out === null) {
            $out = $in; // Rule B: single date → 1-day range.
        }
        if ($out !== null && $in === null) {
            $in = $out;
        }

        return [$in, $out];
    }

    private function normalizeYmd(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function rangeTitle(string $from, string $to): string
    {
        $a = CarbonImmutable::parse($from);
        $b = CarbonImmutable::parse($to);
        if ($a->isSameDay($b)) {
            return $a->format('j M Y');
        }
        if ($a->format('Y') === $b->format('Y')) {
            return $a->format('j M') . ' → ' . $b->format('j M Y');
        }
        return $a->format('j M Y') . ' → ' . $b->format('j M Y');
    }
}
