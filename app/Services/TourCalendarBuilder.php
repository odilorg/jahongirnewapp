<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Resources\BookingInquiryResource;
use App\Models\BookingInquiry;
use Carbon\Carbon;

/**
 * Build the data structure for the Tour Calendar Filament page.
 *
 * Layout the page renders:
 *   rows    = distinct tour products (grouped by tour_slug)
 *   columns = days in the visible window (Mon..Sun for v1)
 *   cells   = clickable booking chips on their travel_date
 *
 * Rows are sorted by their EARLIEST booking in the window so the most
 * imminent tours float to the top, not alphabetically — matches how
 * dispatchers actually scan the schedule.
 */
class TourCalendarBuilder
{
    /**
     * @param  array<int, string>  $statuses  inquiry statuses to include
     * @return array{
     *   from: \Carbon\Carbon,
     *   to:   \Carbon\Carbon,
     *   days: array<int, \Carbon\Carbon>,
     *   rows: array<int, array{slug: ?string, name: string, earliest: string, chips: array}>,
     * }
     */
    public function buildWeek(?Carbon $anchor = null, array $statuses = ['confirmed']): array
    {
        $anchor = $anchor ?? Carbon::today();
        $from   = $anchor->copy()->startOfWeek(Carbon::MONDAY);
        $to     = $anchor->copy()->endOfWeek(Carbon::SUNDAY);

        $inquiries = BookingInquiry::query()
            ->whereIn('status', $statuses)
            ->whereNotNull('travel_date')
            ->whereBetween('travel_date', [$from->toDateString(), $to->toDateString()])
            ->with(['driver', 'guide', 'stays.accommodation'])
            ->orderBy('travel_date')
            ->get();

        // Group by tour_slug; fall back to a normalised snapshot name when
        // slug is missing so two inquiries for the same tour still merge
        // even if one was submitted without a slug.
        $grouped = $inquiries->groupBy(function (BookingInquiry $i): string {
            return $i->tour_slug
                ?: 'snap:' . md5(mb_strtolower(trim((string) $i->tour_name_snapshot)));
        });

        $rows = [];
        foreach ($grouped as $key => $group) {
            $first = $group->first();

            $chips = [];
            foreach ($group as $inq) {
                $chips[] = $this->buildChip($inq);
            }

            $rows[] = [
                'slug'     => $first->tour_slug,
                'name'     => $this->cleanTourName((string) $first->tour_name_snapshot),
                'earliest' => $group->min('travel_date')->toDateString(),
                'chips'    => $chips,
            ];
        }

        // Sort rows by earliest booking date asc — busiest day first.
        usort($rows, fn (array $a, array $b): int => strcmp($a['earliest'], $b['earliest']));

        // Build the day list (7 days, Mon..Sun)
        $days = [];
        $cursor = $from->copy();
        while ($cursor->lte($to)) {
            $days[] = $cursor->copy();
            $cursor->addDay();
        }

        return [
            'from' => $from,
            'to'   => $to,
            'days' => $days,
            'rows' => $rows,
        ];
    }

    /**
     * Strip the brand suffix and trim long page-title style snapshots
     * down to a clean row label. Examples:
     *   "Nuratau Homestay 3 Days 2 Nights from Samarkand | Jahongir Travel"
     *     → "Nuratau Homestay 3 Days 2 Nights from Samarkand"
     */
    private function cleanTourName(string $raw): string
    {
        $cleaned = preg_replace('/\s*\|\s*Jahongir\s+Travel\s*$/iu', '', $raw) ?? $raw;
        $cleaned = trim($cleaned);

        return mb_strlen($cleaned) > 60
            ? mb_substr($cleaned, 0, 57) . '…'
            : $cleaned;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildChip(BookingInquiry $inq): array
    {
        $nightsTotal = (int) $inq->stays->sum('nights');
        $duration    = $nightsTotal > 0 ? $nightsTotal + 1 : 1;

        $accommodations = $inq->stays
            ->map(fn ($s) => $s->accommodation?->name)
            ->filter()
            ->values()
            ->all();

        $paxLabel = $inq->people_children > 0
            ? "{$inq->people_adults}+{$inq->people_children}"
            : (string) $inq->people_adults;

        // 0 = Monday, 6 = Sunday — matches the column order in the grid.
        $dayIndex = (int) $inq->travel_date->format('N') - 1;

        return [
            'id'                => $inq->id,
            'reference'         => $inq->reference,
            'customer_name'     => (string) $inq->customer_name,
            'customer_phone'    => (string) $inq->customer_phone,
            'customer_country'  => $inq->customer_country,
            'pax_label'         => $paxLabel,
            'duration'          => $duration,
            'status'            => $inq->status,
            'payment_method'    => $inq->payment_method,
            'paid_at'           => $inq->paid_at?->toDateString(),
            'travel_date'       => $inq->travel_date->format('M j'),
            'pickup_time'       => $inq->pickup_time,
            'pickup_point'      => $inq->pickup_point,
            'driver_name'       => $inq->driver?->full_name,
            'guide_name'        => $inq->guide?->full_name,
            'accommodations'    => $accommodations,
            'day_index'         => $dayIndex,
            'detail_url'        => BookingInquiryResource::getUrl('view', ['record' => $inq->id]),
        ];
    }
}
