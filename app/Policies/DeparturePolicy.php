<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Departure;

/**
 * Type-conditional behavior resolver for Departure.
 *
 * See PHASE_1_DEPARTURE_CORE_SPEC.md §3.2.c.
 *
 * Single source of truth for "what does tour_type imply?". Anywhere code is
 * tempted to write `if ($departure->tour_type === Departure::TYPE_PRIVATE)`,
 * call a method on this policy instead. The day a third tour_type appears
 * (e.g. b2b-only, fam-trip), this is the only edit site.
 *
 * Not a Laravel Gate/authorization policy — this is a domain policy. Named
 * "Policy" because that is the name of this concept; it just happens to share
 * the suffix with auth policies.
 */
final class DeparturePolicy
{
    /**
     * Whether the auto-cancel cron may cancel this departure when
     * minimum_pax is not met by guarantee_at. Group only — private
     * departures match capacity to their booking pax by construction.
     */
    public function allowsAutoCancel(Departure $departure): bool
    {
        return $departure->tour_type === Departure::TYPE_GROUP;
    }

    /**
     * Whether minimum_pax is enforced as a separate threshold (group)
     * vs. auto-set to capacity_seats (private).
     */
    public function requiresMinimumPax(Departure $departure): bool
    {
        return $departure->tour_type === Departure::TYPE_GROUP;
    }

    /**
     * Whether the departure may appear in public listings, sitemap,
     * or any customer-facing surface.
     */
    public function isPubliclyListable(Departure $departure): bool
    {
        return $departure->tour_type === Departure::TYPE_GROUP
            && in_array($departure->status, Departure::PUBLIC_STATUSES, true);
    }

    /**
     * Whether minimum_pax + guarantee_at fields appear on the Filament form.
     * Private departures hide these (auto-derived).
     */
    public function showsThresholdFields(Departure $departure): bool
    {
        return $departure->tour_type === Departure::TYPE_GROUP;
    }
}
