<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BookingInquiry;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Phase 24 — Group Matching Engine (MVP).
 *
 * Pure query service. Identifies potential group clusters among active
 * leads: same tour + same direction + travel dates within window + pax
 * under cap. Operator-driven — never auto-merges.
 *
 * Refinements over basic v1:
 *   - Revenue vs group-rate estimate (not just sum of private quotes)
 *   - Cost estimate + margin per cluster
 *   - Urgency score — sort by closest travel_date first
 *   - Confidence indicator (🟢 exact same day / 🟡 ±1-2 days)
 *   - Duplicate-lead filter (same phone OR email excluded from matching)
 */
class GroupMatchingEngine
{
    public const ACTIONABLE_STATUSES = [
        BookingInquiry::STATUS_NEW,
        BookingInquiry::STATUS_CONTACTED,
        BookingInquiry::STATUS_AWAITING_CUSTOMER,
        BookingInquiry::STATUS_AWAITING_PAYMENT,
    ];

    public function findClusters(): Collection
    {
        $windowDays     = (int) config('matching.window_days', 2);
        $maxPax         = (int) config('matching.max_pax', 8);
        $minDays        = (int) config('matching.min_days_before_travel', 2);
        $earliestTravel = now()->addDays($minDays)->toDateString();

        // Pull all candidate inquiries — filtered in PHP for clarity + safety
        $candidates = BookingInquiry::query()
            ->whereIn('status', self::ACTIONABLE_STATUSES)
            ->whereNotNull('travel_date')
            ->where('travel_date', '>=', $earliestTravel)
            ->whereNotNull('tour_product_id') // require catalog link
            ->with(['tourProduct', 'tourProductDirection', 'assignedToUser'])
            ->get();

        $clusters = collect();
        $seen     = []; // track used inquiry IDs to dedupe A+B = B+A

        foreach ($candidates as $a) {
            if (in_array($a->id, $seen, true)) continue;

            $members = [$a];
            $paxSum  = (int) ($a->people_adults ?? 0);

            foreach ($candidates as $b) {
                if ($b->id === $a->id) continue;
                if (in_array($b->id, $seen, true)) continue;
                if (! $this->isCompatible($a, $b, $windowDays)) continue;

                // Duplicate lead guard — same guest submitted twice
                if ($this->isSameGuest($a, $b)) continue;

                // Pax cap check (cumulative across all current members)
                $bPax = (int) ($b->people_adults ?? 0);
                if ($paxSum + $bPax > $maxPax) continue;

                // Check B is compatible with ALL existing members, not just A
                $compatibleWithAll = true;
                foreach ($members as $m) {
                    if ($m->id === $a->id) continue;
                    if (! $this->isCompatible($m, $b, $windowDays) || $this->isSameGuest($m, $b)) {
                        $compatibleWithAll = false;
                        break;
                    }
                }
                if (! $compatibleWithAll) continue;

                $members[] = $b;
                $paxSum   += $bPax;
            }

            if (count($members) >= 2) {
                foreach ($members as $m) $seen[] = $m->id;
                $clusters->push($this->buildClusterDto($members));
            }
        }

        // Sort by urgency: closest travel date first
        return $clusters->sortBy('urgency_score')->values();
    }

    private function isCompatible(BookingInquiry $a, BookingInquiry $b, int $windowDays): bool
    {
        // Same tour product
        if ($a->tour_product_id !== $b->tour_product_id) return false;

        // Same direction (both null also counts as "same — no preference")
        if ($a->tour_product_direction_id !== $b->tour_product_direction_id) return false;

        // Travel dates within window
        $dateA = Carbon::parse($a->travel_date);
        $dateB = Carbon::parse($b->travel_date);
        if (abs($dateA->diffInDays($dateB, false)) > $windowDays) return false;

        return true;
    }

    private function isSameGuest(BookingInquiry $a, BookingInquiry $b): bool
    {
        $normPhone = fn ($p) => preg_replace('/[^0-9]/', '', (string) $p);
        if ($a->customer_phone && $b->customer_phone
            && $normPhone($a->customer_phone) === $normPhone($b->customer_phone)) {
            return true;
        }
        if ($a->customer_email && $b->customer_email
            && strtolower(trim($a->customer_email)) === strtolower(trim($b->customer_email))) {
            return true;
        }
        return false;
    }

    /**
     * @param array<BookingInquiry> $members
     */
    private function buildClusterDto(array $members): array
    {
        $first = $members[0];
        $slug  = $first->tour_product?->slug ?? 'default';
        $rates = config('matching.group_rate_per_person_usd', []);
        $costs = config('matching.estimated_group_cost_usd', []);
        $groupRate = $rates[$slug] ?? $rates['default'] ?? 150;
        $groupCost = $costs[$slug] ?? $costs['default'] ?? 200;

        $totalPax         = array_sum(array_map(fn ($m) => (int) $m->people_adults, $members));
        $currentRevenue   = array_sum(array_map(fn ($m) => (float) ($m->price_quoted ?? 0), $members));
        $estimatedRevenue = $totalPax * $groupRate;
        $estimatedMargin  = $estimatedRevenue - $groupCost;

        // Earliest travel date across the cluster
        $travelDates = array_map(fn ($m) => Carbon::parse($m->travel_date), $members);
        $earliest    = min($travelDates);

        // Urgency = days until earliest departure (negative = past, shouldn't happen)
        $urgencyScore = $earliest->diffInDays(now()->startOfDay(), false);

        // Confidence — exact same day vs ±N
        $uniqueDates = collect($members)->pluck('travel_date')->map(
            fn ($d) => Carbon::parse($d)->toDateString()
        )->unique();
        $confidence = $uniqueDates->count() === 1 ? 'exact' : 'flexible';

        return [
            'members'            => collect($members)->map(fn ($m) => [
                'id'             => $m->id,
                'reference'      => $m->reference,
                'customer_name'  => $m->customer_name,
                'customer_phone' => $m->customer_phone,
                'pax'            => (int) $m->people_adults,
                'status'         => $m->status,
                'price_quoted'   => (float) ($m->price_quoted ?? 0),
                'travel_date'    => $m->travel_date?->format('Y-m-d'),
                'travel_label'   => $m->travel_date?->format('M j'),
                'source'         => $m->source,
                'wa_phone'       => preg_replace('/[^0-9]/', '', (string) $m->customer_phone),
            ])->all(),
            'tour_name'          => $first->tourProduct?->title ?? $first->tour_name_snapshot,
            'direction'          => $first->tourProductDirection?->name,
            'total_pax'          => $totalPax,
            'earliest_date'      => $earliest->format('Y-m-d'),
            'earliest_label'     => $earliest->format('M j, Y'),
            'current_revenue'    => $currentRevenue,
            'estimated_revenue'  => $estimatedRevenue,
            'estimated_cost'     => $groupCost,
            'estimated_margin'   => $estimatedMargin,
            'estimated_margin_pct' => $estimatedRevenue > 0
                ? round(($estimatedMargin / $estimatedRevenue) * 100)
                : 0,
            'group_rate_per_pp'  => $groupRate,
            'urgency_score'      => $urgencyScore,
            'confidence'         => $confidence,
        ];
    }

    /**
     * Shared WhatsApp message template for group proposals.
     */
    public static function buildWhatsAppMessage(string $name, float $groupRate, string $dateLabel): string
    {
        return "Hi {$name},\n\n"
            . "Good news — we have another traveler interested in the same tour around your dates.\n\n"
            . "We can organize a small group and offer a better rate:\n"
            . "👉 \${$groupRate} per person instead of private pricing\n\n"
            . "Travel date: {$dateLabel}\n\n"
            . "Would you like to join?\n\n"
            . "Best regards,\n"
            . "Jahongir Travel";
    }
}
