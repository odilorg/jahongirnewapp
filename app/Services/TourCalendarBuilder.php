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
    /**
     * Phase 20 — Action View. Groups active bookings by urgency instead
     * of by date. Returns priority zones:
     *   needs_action_today, tomorrow_prep, ready_today_tomorrow, ready_later
     *
     * Summary counts also returned for the top strip.
     */
    public function buildActionView(?int $assignedToUserId = null): array
    {
        $today    = Carbon::today();
        $tomorrow = $today->copy()->addDay();
        $weekEnd  = $today->copy()->addDays(7);

        $statuses = [
            BookingInquiry::STATUS_CONFIRMED,
            BookingInquiry::STATUS_AWAITING_PAYMENT,
        ];

        $inquiries = BookingInquiry::query()
            ->whereIn('status', $statuses)
            ->whereNotNull('travel_date')
            ->whereBetween('travel_date', [$today->toDateString(), $weekEnd->toDateString()])
            ->when($assignedToUserId, fn ($q) => $q->where('assigned_to_user_id', $assignedToUserId))
            ->with(['driver', 'guide', 'stays.accommodation', 'tourProduct', 'tourProductDirection', 'assignedToUser'])
            ->orderBy('travel_date')
            ->orderBy('pickup_time')
            ->get();

        $this->windowFrom = $today;

        $needsActionToday = [];
        $tomorrowPrep     = [];
        $ready            = [];
        $totalTodayRev    = 0;

        foreach ($inquiries as $inq) {
            $chip       = $this->buildChip($inq);
            $readiness  = $this->computeReadiness($inq);
            $chip['readiness_chips']   = $readiness['chips'];
            $chip['action_reasons']    = $readiness['reasons'];
            $chip['needs_action']      = ! empty($readiness['reasons']);
            $chip['travel_date_raw']   = $inq->travel_date->toDateString();
            $chip['travel_date_label'] = $inq->travel_date->format('M j');

            $travelDate = Carbon::parse($inq->travel_date);
            $isToday    = $travelDate->isToday();
            $isTomorrow = $travelDate->isTomorrow();

            if ($isToday) {
                $totalTodayRev += (float) ($inq->price_quoted ?? 0);
            }

            if ($chip['needs_action'] && $isToday) {
                $needsActionToday[] = $chip;
            } elseif ($chip['needs_action'] && $isTomorrow) {
                $tomorrowPrep[] = $chip;
            } else {
                $ready[] = $chip;
            }
        }

        // Active leads — inquiries nobody owns yet, or still in early workflow.
        // Broadened to include 'contacted' + 'awaiting_customer' because
        // those are bookings we reached out to but haven't confirmed yet —
        // still need operator attention (price, follow-up, confirmation).
        $unclaimed = BookingInquiry::query()
            ->whereIn('status', [
                BookingInquiry::STATUS_NEW,
                BookingInquiry::STATUS_CONTACTED,
                BookingInquiry::STATUS_AWAITING_CUSTOMER,
            ])
            ->whereIn('source', ['website', 'whatsapp', 'manual', 'phone'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['id', 'reference', 'customer_name', 'source', 'status', 'created_at', 'assigned_to_user_id']);

        // Phase 21 — due reminders count
        $dueRemindersCount = \App\Models\InquiryReminder::pending()
            ->where('remind_at', '<=', now())
            ->when($assignedToUserId, fn ($q) => $q->where('assigned_to_user_id', $assignedToUserId))
            ->count();

        // Phase 24 — group match opportunities count
        $groupMatchesCount = app(\App\Services\GroupMatchingEngine::class)->findClusters()->count();

        return [
            'today_count'        => count($needsActionToday) + count(array_filter($ready, fn ($c) => Carbon::parse($c['travel_date_raw'])->isToday())),
            'today_revenue'      => $totalTodayRev,
            'needs_action_count' => count($needsActionToday),
            'tomorrow_count'     => count($tomorrowPrep) + count(array_filter($ready, fn ($c) => Carbon::parse($c['travel_date_raw'])->isTomorrow())),
            'unclaimed_count'    => $unclaimed->count(),
            'reminders_due'      => $dueRemindersCount,
            'group_matches'      => $groupMatchesCount,
            'unclaimed'          => $unclaimed->map(fn ($i) => [
                'id'            => $i->id,
                'reference'     => $i->reference,
                'customer_name' => $i->customer_name,
                'source'        => $i->source,
                'status'        => $i->status,
                'age'           => $i->created_at->diffForHumans(),
                'assigned'      => $i->assigned_to_user_id !== null,
            ])->toArray(),
            'needs_action_today' => $needsActionToday,
            'tomorrow_prep'      => $tomorrowPrep,
            'ready'              => $ready,
        ];
    }

    /**
     * Phase 20 — compute explicit readiness chips + the list of reasons
     * an inquiry needs operator action. Returns both so the UI can show
     * 🟢/🔴 chips AND the list of action reasons.
     */
    /**
     * Public accessor for computeReadiness — used by DailyRecapBuilder
     * so recap chips match dispatch board exactly.
     */
    public function computeReadinessPublic(BookingInquiry $inq): array
    {
        return $this->computeReadiness($inq);
    }

    private function computeReadiness(BookingInquiry $inq): array
    {
        $chips   = [];
        $reasons = [];

        // Payment
        $isPaid = $inq->paid_at !== null;
        $chips['paid'] = $isPaid;
        if (! $isPaid && $inq->status === BookingInquiry::STATUS_AWAITING_PAYMENT) {
            $reasons[] = 'unpaid';
        }

        // Driver
        $driverAssigned   = (bool) $inq->driver_id;
        $driverDispatched = $driverAssigned && str_contains(
            (string) $inq->internal_notes,
            'Calendar dispatch TG → driver'
        );
        $chips['driver'] = $driverDispatched
            ? 'dispatched'
            : ($driverAssigned ? 'assigned' : 'missing');
        if (! $driverAssigned) {
            $reasons[] = 'no driver';
        } elseif (! $driverDispatched) {
            $reasons[] = 'driver not dispatched';
        }

        // Pickup
        $hasPickup = filled($inq->pickup_point)
            && ! in_array($inq->pickup_point, ['Samarkand', 'Gur Emir Mausoleum'], true);
        $chips['pickup'] = $hasPickup;
        if (! $hasPickup) {
            $reasons[] = 'no pickup';
        }

        // Accommodation — Phase 20.8. Aligns UI with Phase 19.3a backend.
        // State per stay: none | missing-accommodation | assigned | dispatched.
        // Chip reports the weakest stay: if ANY is missing, show red.
        $stays = $inq->stays ?? collect();
        if ($stays->isEmpty()) {
            $chips['accommodation'] = 'none'; // no stays at all → could be a day tour
        } else {
            $notes    = (string) $inq->internal_notes;
            $allDispatched = true;
            $anyMissingAcc = false;
            foreach ($stays as $stay) {
                if (! $stay->accommodation_id) {
                    $anyMissingAcc = true;
                    $allDispatched = false;
                    continue;
                }
                $name = $stay->accommodation?->name;
                if (! $name || ! str_contains($notes, "Calendar dispatch TG → stay {$name}")) {
                    $allDispatched = false;
                }
            }

            if ($anyMissingAcc) {
                $chips['accommodation'] = 'missing';
                $reasons[] = 'accommodation not assigned';
            } elseif ($allDispatched) {
                $chips['accommodation'] = 'dispatched';
            } else {
                $chips['accommodation'] = 'assigned';
                $reasons[] = 'accommodation not dispatched';
            }
        }

        return ['chips' => $chips, 'reasons' => $reasons];
    }

    public function buildWeek(?Carbon $anchor = null, array $statuses = ['confirmed'], bool $startFromAnchor = false, ?int $assignedToUserId = null): array
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
            ->when($assignedToUserId, fn ($q) => $q->where('assigned_to_user_id', $assignedToUserId))
            ->with(['driver', 'guide', 'stays.accommodation', 'tourProduct', 'tourProductDirection', 'assignedToUser'])
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
            'assigned_to'       => $inq->assignedToUser?->name,
            'assigned_initials' => $inq->assignedToUser
                ? strtoupper(mb_substr($inq->assignedToUser->name, 0, 2))
                : null,
            'source_badge'      => $sourceBadge,
            'display_state'     => $displayState,
            'readiness'         => $readiness,
            'warnings'          => $warnings,
            'detail_url'        => BookingInquiryResource::getUrl('view', ['record' => $inq->id]),
        ];
    }
}
