<?php

declare(strict_types=1);

namespace App\Enums;

use Carbon\CarbonInterface;

/**
 * The catalogue of timed guest-care touchpoints (Phase 29).
 *
 * Each case knows how to compute its own due time relative to a stable
 * anchor — the tour departure (travel_date + pickup_time, Asia/Tashkent).
 * Two offset primitives only:
 *
 *   - relative: departure + duration   (e.g. welcome = departure + 1h)
 *   - localTimeOnDay: a fixed clock time on tour day N
 *     (e.g. sunset tip = day 1 18:30; feedback = day 2 08:30)
 *
 * A future 3-day tour adds new cases + catalog content; no schema change.
 */
enum GuestExperienceMessageType: string
{
    case PostPickupWelcome = 'post_pickup_welcome';
    case EveningSunsetTip = 'evening_sunset_tip';
    case NextMorningFeedback = 'next_morning_feedback';

    /**
     * Compute the due time for this message, given the tour departure
     * (already in Asia/Tashkent). Returns a Carbon in the same timezone.
     */
    public function dueAt(CarbonInterface $departure): CarbonInterface
    {
        return match ($this) {
            self::PostPickupWelcome => $departure->copy()->addHour(),
            self::EveningSunsetTip => self::localTimeOnDay($departure, dayIndex: 1, hour: 18, minute: 30),
            self::NextMorningFeedback => self::localTimeOnDay($departure, dayIndex: 2, hour: 8, minute: 30),
        };
    }

    /**
     * The 1-based tour day this message belongs to. A tour must run at
     * least this many days for the message to apply.
     */
    public function requiresDayCount(): int
    {
        return match ($this) {
            self::PostPickupWelcome, self::EveningSunsetTip => 1,
            self::NextMorningFeedback => 2,
        };
    }

    /** All types, in chronological order within a tour. */
    public static function ordered(): array
    {
        return [
            self::PostPickupWelcome,
            self::EveningSunsetTip,
            self::NextMorningFeedback,
        ];
    }

    /** Day N at a fixed local clock time. Day 1 = departure's calendar day. */
    private static function localTimeOnDay(
        CarbonInterface $departure,
        int $dayIndex,
        int $hour,
        int $minute,
    ): CarbonInterface {
        return $departure->copy()
            ->startOfDay()
            ->addDays($dayIndex - 1)
            ->setTime($hour, $minute);
    }
}
