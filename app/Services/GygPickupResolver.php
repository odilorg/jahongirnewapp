<?php

namespace App\Services;

use App\Enums\GygBookingType;
use App\Models\GygInboundEmail;

/**
 * Single source of truth for resolving pickup_location on GYG bookings.
 *
 * This class centralises a business rule that must not be duplicated:
 *   - Group tours  → fixed public meeting point (Gur Emir Mausoleum)
 *   - Private tours → null, so downstream logic asks the guest for their hotel
 *
 * Classification uses the strongest available signal in a defined priority order
 * (see classify() docblock). The priority order is intentional and documented so
 * future maintainers can reason about edge cases without reading parser internals.
 */
class GygPickupResolver
{
    /** Fixed meeting point for all group tours. */
    public const GROUP_MEETING_POINT = 'Gur Emir Mausoleum';

    /**
     * Resolve pickup_location from a parsed GYG inbound email.
     *
     * Returns GROUP_MEETING_POINT for group tours.
     * Returns null for private/unknown tours so:
     *   - GygPostBookingMailer sends a hotel-request message to the guest.
     *   - TourSendReminders falls back to "your hotel" in the WA reminder.
     *   - Staff can manually fill in the column once the guest replies.
     */
    public function resolveFromEmail(GygInboundEmail $email): ?string
    {
        $type = $this->classify(
            tourType:       $email->tour_type,
            tourTypeSource: $email->tour_type_source,
            optionTitle:    $email->option_title,
            tourName:       $email->tour_name,
        );

        return $type->isGroup() ? self::GROUP_MEETING_POINT : null;
    }

    /**
     * Classify booking type using a defined signal priority order.
     *
     * Priority (highest to lowest):
     *   1. Explicit parser result (tour_type_source = 'explicit')
     *      The parser found a 'group' keyword in the title context — strongest signal.
     *   2. option_title keyword heuristics ('group' / 'private' substring)
     *      Catches emails where tour_type is null or was not stored, but the option
     *      title clearly indicates the type (e.g. "Private Shahrisabz Day Trip").
     *   3. tour_name keyword heuristics ('group' / 'private' substring)
     *      Secondary structural signal from the product-level title.
     *   4. Defaulted parser result (tour_type_source = 'defaulted')
     *      Parser defaulted to 'private' because no 'group' keyword was found.
     *      Weaker than option_title heuristics because 'defaulted' just means
     *      the parser's else-branch fired, not that the type was confirmed.
     *   5. Conservative fallback → Private
     *      Better to prompt a hotel request than to send a guest to the wrong
     *      public meeting point.
     */
    public function classify(
        ?string $tourType,
        ?string $tourTypeSource,
        ?string $optionTitle,
        ?string $tourName = null,
    ): GygBookingType {
        // Priority 1: Explicit parser result
        if ($tourTypeSource === 'explicit') {
            return GygBookingType::fromParsed($tourType);
        }

        // Priority 2: option_title keyword heuristics
        $optionContext = strtolower(trim($optionTitle ?? ''));
        if ($optionContext !== '') {
            if (str_contains($optionContext, 'group')) {
                return GygBookingType::Group;
            }
            if (str_contains($optionContext, 'private')) {
                return GygBookingType::Private;
            }
        }

        // Priority 3: tour_name keyword heuristics
        $nameContext = strtolower(trim($tourName ?? ''));
        if ($nameContext !== '') {
            if (str_contains($nameContext, 'group')) {
                return GygBookingType::Group;
            }
            if (str_contains($nameContext, 'private')) {
                return GygBookingType::Private;
            }
        }

        // Priority 4: Defaulted parser result
        if ($tourType !== null) {
            return GygBookingType::fromParsed($tourType);
        }

        // Priority 5: Conservative fallback
        return GygBookingType::Private;
    }
}
