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
    private Carbon $windowFrom;

    /**
     * @param  array<int, string>  $statuses  inquiry statuses to include
     * @return array{
     *   from: \Carbon\Carbon,
     *   to:   \Carbon\Carbon,
     *   days: array<int, \Carbon\Carbon>,
     *   rows: array<int, array{slug: ?string, name: string, earliest: string, chips: array}>,
     * }
     */
    public function buildWeek(?Carbon $anchor = null, array $statuses = ['confirmed'], bool $startFromAnchor = false): array
    {
        $anchor = $anchor ?? Carbon::today();

        if ($startFromAnchor) {
            // "Today" mode: 7 days starting from the anchor date
            $from = $anchor->copy()->startOfDay();
            $to   = $anchor->copy()->addDays(6)->endOfDay();
        } else {
            // Standard Mon–Sun week containing the anchor
            $from = $anchor->copy()->startOfWeek(Carbon::MONDAY);
            $to   = $anchor->copy()->endOfWeek(Carbon::SUNDAY);
        }

        $this->windowFrom = $from;

        $inquiries = BookingInquiry::query()
            ->whereIn('status', $statuses)
            ->whereNotNull('travel_date')
            ->whereBetween('travel_date', [$from->toDateString(), $to->toDateString()])
            ->with(['driver', 'guide', 'stays.accommodation', 'tourProduct', 'tourProductDirection'])
            ->orderBy('travel_date')
            ->get();

        // Build a secondary map: slug → product_id, so that legacy inquiries
        // (no FK but matching slug) collapse into the same row as FK-linked
        // ones. Without this merge, a post-backfill linked inquiry and a
        // freshly-manual inquiry with the same slug would appear on two
        // different rows.
        $slugToProductId = $inquiries
            ->filter(fn ($i) => $i->tour_product_id && $i->tour_slug)
            ->mapWithKeys(fn ($i) => [$i->tour_slug => $i->tour_product_id])
            ->all();

        // Group by tour_product_id when present; otherwise by tour_slug
        // (promoted to product_id when the slug matches a known catalog
        // row); otherwise by a hashed snapshot name as last resort.
        $grouped = $inquiries->groupBy(function (BookingInquiry $i) use ($slugToProductId): string {
            if ($i->tour_product_id) {
                return 'product:' . $i->tour_product_id;
            }

            if ($i->tour_slug && isset($slugToProductId[$i->tour_slug])) {
                return 'product:' . $slugToProductId[$i->tour_slug];
            }

            if ($i->tour_slug) {
                return 'slug:' . $i->tour_slug;
            }

            return 'snap:' . md5(mb_strtolower(trim((string) $i->tour_name_snapshot)));
        });

        $rows = [];
        foreach ($grouped as $key => $group) {
            // Prefer the linked tourProduct on any chip in the group for
            // the row label; otherwise fall back to snapshot.
            $linked = $group->first(fn ($i) => $i->tourProduct !== null);
            $rowName = $linked?->tourProduct?->title
                ?? (string) $group->first()->tour_name_snapshot
                ?? (string) $group->first()->tour_slug;

            $chips = [];
            foreach ($group as $inq) {
                $chips[] = $this->buildChip($inq);
            }

            $rows[] = [
                'slug'     => $group->first()->tour_slug,
                'name'     => $this->cleanTourName($rowName),
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

        // Day index relative to the window start date (not always Monday).
        // Stored in $this->windowFrom which is set during buildWeek().
        $dayIndex = (int) $this->windowFrom->diffInDays($inq->travel_date);

        // Readiness: what's missing for this booking to be fully operational?
        $warnings = [];
        if (! $inq->driver_id) {
            $warnings[] = 'no driver';
        }
        if (blank($inq->pickup_point) || $inq->pickup_point === 'Samarkand' || $inq->pickup_point === 'Gur Emir Mausoleum') {
            $warnings[] = 'no pickup';
        }

        // Display state — drives chip color in Blade
        $displayState = match (true) {
            in_array($inq->status, [BookingInquiry::STATUS_CONTACTED, BookingInquiry::STATUS_AWAITING_CUSTOMER])
                => 'lead',
            $inq->status === BookingInquiry::STATUS_AWAITING_PAYMENT
                => 'awaiting_payment',
            $inq->paid_at !== null && empty($warnings)
                => 'ready',
            $inq->paid_at !== null && ! empty($warnings)
                => 'paid_needs_attention',
            $inq->payment_method === BookingInquiry::PAYMENT_CASH || $inq->payment_method === BookingInquiry::PAYMENT_CARD_OFFICE
                => 'confirmed_offline',
            default
                => 'confirmed_offline',
        };

        $readiness = empty($warnings) ? 'ready' : implode(', ', $warnings);

        // Source badge
        $sourceBadge = match ($inq->source) {
            'gyg'      => 'GYG',
            'website'  => 'WEB',
            'whatsapp' => 'WA',
            'telegram' => 'TG',
            'phone'    => 'PH',
            'email'    => 'EM',
            default    => strtoupper(mb_substr($inq->source, 0, 3)),
        };

        // WhatsApp deep link
        $waPhone = preg_replace('/[^0-9]/', '', (string) $inq->customer_phone);

        return [
            'id'                => $inq->id,
            'reference'         => $inq->reference,
            'customer_name'     => (string) $inq->customer_name,
            'customer_phone'    => (string) $inq->customer_phone,
            'wa_phone'          => $waPhone,
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
            'driver_phone'      => $inq->driver?->phone01,
            'guide_name'        => $inq->guide?->full_name,
            'guide_phone'       => $inq->guide?->phone01,
            'accommodations'    => $accommodations,
            'day_index'         => $dayIndex,
            'tour_type'         => $inq->tour_type,
            'source_badge'      => $sourceBadge,
            'display_state'     => $displayState,
            'readiness'         => $readiness,
            'warnings'          => $warnings,
            'detail_url'        => BookingInquiryResource::getUrl('view', ['record' => $inq->id]),
        ];
    }
}
