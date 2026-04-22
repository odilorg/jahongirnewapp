<?php

declare(strict_types=1);

namespace App\Services\BookingBot;

use App\Support\BookingBot\DateRangeParser;
use Carbon\CarbonImmutable;

/**
 * Deterministic intent parser for view / cancel / search commands.
 *
 * Phase 10.4 local-first strategy. Covers the ~80 % of daily operator
 * traffic that follows narrow grammar — returns `null` for the rest so
 * the coordinator falls through to DeepSeekIntentParser. Never guesses.
 *
 * Input is expected to be pre-normalized by MessageNormalizer (trim,
 * lowercase, unicode-dash fixup, whitespace collapse). Keeping the
 * normalization responsibility in the coordinator makes this class
 * purely about pattern matching + date parsing delegation.
 *
 * Return shape matches BookingIntentParser::parse() contract — handlers
 * consume the same array structure either way.
 */
final class LocalIntentParser
{
    public function __construct(
        private readonly DateRangeParser $dateRange,
    ) {}

    /**
     * @return array<string, mixed>|null  null → LLM fallback
     */
    public function tryParse(string $normalizedMessage): ?array
    {
        if ($normalizedMessage === '') {
            return null;
        }

        return $this->matchCancel($normalizedMessage)
            ?? $this->matchShowDetail($normalizedMessage)
            ?? $this->matchInHouseOrCurrent($normalizedMessage)
            ?? $this->matchNewBookings($normalizedMessage)
            ?? $this->matchArrivalsToday($normalizedMessage)
            ?? $this->matchDeparturesToday($normalizedMessage)
            ?? $this->matchToday($normalizedMessage)
            ?? $this->matchTomorrow($normalizedMessage)
            ?? $this->matchArrivalsRange($normalizedMessage)
            ?? $this->matchDeparturesRange($normalizedMessage)
            ?? $this->matchBookingsRange($normalizedMessage)
            ?? $this->matchSearch($normalizedMessage);
    }

    /** @return array<string,mixed>|null */
    private function matchCancel(string $s): ?array
    {
        return preg_match('/^cancel\s+(?:booking\s+)?#?(\d+)$/', $s, $m) === 1
            ? ['intent' => 'cancel_booking', 'booking_id' => $m[1]]
            : null;
    }

    /** @return array<string,mixed>|null */
    private function matchShowDetail(string $s): ?array
    {
        // Handler for this intent ships in Phase 10.5; parser accepts
        // it now so the grammar is stable.
        return preg_match('/^(?:show|details?)\s+#?(\d+)$/', $s, $m) === 1
            ? ['intent' => 'show_booking', 'booking_id' => $m[1]]
            : null;
    }

    /** @return array<string,mixed>|null */
    private function matchInHouseOrCurrent(string $s): ?array
    {
        return preg_match('/^(?:in[- ]?house(?:\s+guests?)?|current(?:\s+bookings?)?)$/', $s) === 1
            ? ['intent' => 'view_bookings', 'filter_type' => 'current']
            : null;
    }

    /** @return array<string,mixed>|null */
    private function matchNewBookings(string $s): ?array
    {
        return preg_match('/^new(?:\s+bookings?)?$/', $s) === 1
            ? ['intent' => 'view_bookings', 'filter_type' => 'new']
            : null;
    }

    /** @return array<string,mixed>|null */
    private function matchArrivalsToday(string $s): ?array
    {
        return preg_match('/^(?:view\s+)?(?:today\'?s\s+)?arrivals?(?:\s+today)?$/', $s) === 1
            ? ['intent' => 'view_bookings', 'filter_type' => 'arrivals_today']
            : null;
    }

    /** @return array<string,mixed>|null */
    private function matchDeparturesToday(string $s): ?array
    {
        return preg_match('/^(?:view\s+)?(?:today\'?s\s+)?departures?(?:\s+today)?$/', $s) === 1
            ? ['intent' => 'view_bookings', 'filter_type' => 'departures_today']
            : null;
    }

    /** @return array<string,mixed>|null */
    private function matchToday(string $s): ?array
    {
        if (preg_match('/^(?:view\s+)?(?:the\s+)?bookings?(?:\s+for)?\s+today$/', $s) === 1
            || $s === 'today') {
            $today = CarbonImmutable::now()->format('Y-m-d');
            return [
                'intent'      => 'view_bookings',
                'filter_type' => 'today',
                'dates'       => ['check_in' => $today, 'check_out' => $today],
            ];
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    private function matchTomorrow(string $s): ?array
    {
        if (preg_match('/^(?:view\s+)?bookings?(?:\s+for)?\s+tomorrow$/', $s) === 1 || $s === 'tomorrow') {
            $d = CarbonImmutable::now()->addDay()->format('Y-m-d');
            return [
                'intent' => 'view_bookings',
                'dates'  => ['check_in' => $d, 'check_out' => $d],
            ];
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    private function matchArrivalsRange(string $s): ?array
    {
        if (preg_match('/^arrivals?\s+(?<rest>.+)$/', $s, $m) === 1) {
            $range = $this->dateRange->parse($m['rest']);
            if ($range !== null) {
                return [
                    'intent'      => 'view_bookings',
                    'filter_type' => 'arrivals',
                    'dates'       => $range,
                ];
            }
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    private function matchDeparturesRange(string $s): ?array
    {
        if (preg_match('/^departures?\s+(?<rest>.+)$/', $s, $m) === 1) {
            $range = $this->dateRange->parse($m['rest']);
            if ($range !== null) {
                return [
                    'intent'      => 'view_bookings',
                    'filter_type' => 'departures',
                    'dates'       => $range,
                ];
            }
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    private function matchBookingsRange(string $s): ?array
    {
        if (preg_match('/^bookings?\s+(?:for\s+|on\s+)?(?<rest>.+)$/', $s, $m) === 1) {
            $range = $this->dateRange->parse($m['rest']);
            if ($range !== null) {
                return [
                    'intent' => 'view_bookings',
                    'dates'  => $range,
                ];
            }
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    private function matchSearch(string $s): ?array
    {
        if (preg_match('/^(?:search|find)\s+(?:for\s+|booking\s+)?(?<q>.+)$/', $s, $m) === 1) {
            $q = trim((string) $m['q']);
            if ($q !== '' && mb_strlen($q) <= 60) {
                return ['intent' => 'view_bookings', 'search_string' => $q];
            }
        }
        return null;
    }
}
