<?php

declare(strict_types=1);

namespace App\Services\BookingBot;

use App\Models\RoomUnitMapping;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Formats a list of Beds24 bookings into the Telegram reply for the
 * view-bookings intent. Pure — no DB, no HTTP.
 *
 * Phase 9.2 — compact rows + collapsed group bookings + name abbreviation.
 *
 * Presentation rules (operators scan, they don't read):
 *   - One booking = one compact line: `#id · guest · prop unit · Nn [status]`
 *   - Guest names abbreviated: "Jose Miguel Frances Hierro" → "J.M.F.Hierro"
 *   - Slash-suffix notes trimmed: "Jacques TURRI /Airport transfer 12$/..." → "J.TURRI /Airport"
 *   - Multi-row group bookings (same guest + arrival + departure + property)
 *     collapse into ONE line: "Guest ×N · Prop 10,12,14 · Nn"
 *   - Property shortened to "Hotel" / "Prem" once it's clear which one
 *   - Status icon only when non-confirmed (✅ Confirmed is noise on 90% of rows)
 *   - Nights shown only when ≥ 2 (1-night stays are unambiguous)
 *
 * Modes unchanged from Phase 9.1: arrivals / departures / stays / none.
 */
final class BookingListFormatter
{
    public const MODE_ARRIVALS   = 'arrivals';
    public const MODE_DEPARTURES = 'departures';
    public const MODE_STAYS      = 'stays';
    public const MODE_NONE       = 'none';

    private const MAX_GUEST_CHARS = 22;

    /**
     * @param array<int, array<string, mixed>> $bookings Raw Beds24 /bookings rows.
     * @param Collection<int, RoomUnitMapping> $rooms    For unit_name/room_name lookup.
     */
    public function format(
        array $bookings,
        string $title,
        Collection $rooms,
        string $mode = self::MODE_STAYS,
    ): string {
        $count = count($bookings);
        if ($count === 0) {
            return "No bookings found for {$title}.";
        }

        $maxRows = (int) config('hotel_booking_bot.view.max_rows', 30);

        $sortKey = $this->sortKey($mode);
        usort($bookings, static fn (array $a, array $b) => strcmp(
            (string) ($a[$sortKey] ?? ''),
            (string) ($b[$sortKey] ?? ''),
        ));

        // Collapse multi-room group bookings (same guest + dates + property)
        // BEFORE capping and rendering. A single group replaces N rows, which
        // is the biggest win on high-volume days (e.g. "Orient Insight ×8").
        $collapsed = $this->collapseGroups($bookings);
        $collapsedCount = count($collapsed);

        $shown    = array_slice($collapsed, 0, $maxRows);
        $overflow = max(0, $collapsedCount - $maxRows);

        $hasMixedProperty = $shown
            ? count(array_unique(array_map(static fn ($b) => (string) ($b['propertyId'] ?? ''), $shown))) > 1
            : false;

        $header = "{$title} · {$count}";

        $body = $mode === self::MODE_NONE
            ? $this->renderFlat($shown, $rooms, $hasMixedProperty)
            : $this->renderGrouped($shown, $rooms, $sortKey, $hasMixedProperty);

        $footer = $overflow > 0
            ? "\n+{$overflow} more (narrow your query)"
            : '';

        return $header . "\n\n" . rtrim($body) . $footer;
    }

    private function sortKey(string $mode): string
    {
        return $mode === self::MODE_DEPARTURES ? 'departure' : 'arrival';
    }

    /**
     * Merge bookings that share guest + arrival + departure + property into
     * a single synthetic row with __count and __unitIds added. Order is
     * preserved (first occurrence wins position).
     *
     * @param array<int, array<string, mixed>> $bookings
     * @return array<int, array<string, mixed>>
     */
    private function collapseGroups(array $bookings): array
    {
        $groups = [];
        $order  = [];

        foreach ($bookings as $b) {
            $key = implode('|', [
                strtolower(trim(((string) ($b['firstName'] ?? '')) . ' ' . ((string) ($b['lastName'] ?? '')))),
                (string) ($b['arrival'] ?? ''),
                (string) ($b['departure'] ?? ''),
                (string) ($b['propertyId'] ?? ''),
            ]);

            if (!isset($groups[$key])) {
                $groups[$key] = $b;
                $groups[$key]['__count']   = 1;
                $groups[$key]['__roomIds'] = [$b['roomId'] ?? null];
                $order[] = $key;
                continue;
            }

            $groups[$key]['__count']++;
            $groups[$key]['__roomIds'][] = $b['roomId'] ?? null;
        }

        // Preserve original ordering (already date-sorted upstream).
        return array_map(static fn (string $k) => $groups[$k], $order);
    }

    /**
     * @param array<int, array<string, mixed>> $bookings
     * @param Collection<int, RoomUnitMapping> $rooms
     */
    private function renderGrouped(array $bookings, Collection $rooms, string $groupKey, bool $mixedProperty): string
    {
        $groups = [];
        foreach ($bookings as $b) {
            $d = (string) ($b[$groupKey] ?? 'N/A');
            $groups[$d] ??= [];
            $groups[$d][] = $b;
        }
        ksort($groups);

        $out = '';
        foreach ($groups as $date => $rows) {
            $heading = $this->shortDate($date);
            $rowCount = $this->expandedRowCount($rows);
            $countHint = $rowCount > count($rows) ? " ({$rowCount})" : '';
            $out .= "{$heading}{$countHint}\n";
            foreach ($rows as $b) {
                $out .= '  ' . $this->renderRow($b, $rooms, $mixedProperty) . "\n";
            }
            $out .= "\n";
        }
        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $bookings
     * @param Collection<int, RoomUnitMapping> $rooms
     */
    private function renderFlat(array $bookings, Collection $rooms, bool $mixedProperty): string
    {
        $out = '';
        foreach ($bookings as $b) {
            $out .= $this->renderRow($b, $rooms, $mixedProperty) . "\n";
        }
        return $out;
    }

    /**
     * One booking line. Compact so 30 rows fit in one Telegram message
     * even for long guest names and group bookings.
     */
    private function renderRow(array $b, Collection $rooms, bool $mixedProperty): string
    {
        $id      = (string) ($b['id'] ?? '?');
        $guest   = $this->abbreviateGuest((string) ($b['firstName'] ?? ''), (string) ($b['lastName'] ?? ''));
        $count   = (int) ($b['__count'] ?? 1);
        $roomIds = (array) ($b['__roomIds'] ?? [$b['roomId'] ?? null]);

        if ($count > 1) {
            $guest .= ' ×' . $count;
        }

        $propAndUnits = $this->formatPropertyAndUnits($roomIds, $rooms, $mixedProperty);
        $nights       = $this->nights((string) ($b['arrival'] ?? ''), (string) ($b['departure'] ?? ''));
        $nightsLabel  = $nights >= 2 ? " · {$nights}n" : '';
        $statusLabel  = $this->statusLabel((string) ($b['status'] ?? ''));

        return "#{$id} · {$guest} · {$propAndUnits}{$nightsLabel}{$statusLabel}";
    }

    /**
     * Abbreviate a guest name. Rules:
     *  - First up to 3 given-name initials, then full last name.
     *    "Jose Miguel Frances Hierro" → "J.M.F.Hierro"
     *  - If name has a slash-suffix note, keep only the portion up to the
     *    first slash plus ≤ 8 chars of note:
     *    "Jacques TURRI /Airport transfer 12$/..." → "J.TURRI /Airport"
     *  - Hard cap at MAX_GUEST_CHARS characters; ellipsis appended if trimmed.
     */
    private function abbreviateGuest(string $first, string $last): string
    {
        $raw = trim($first . ' ' . $last);
        if ($raw === '') {
            return 'N/A';
        }

        $slashPos = strpos($raw, '/');
        $tail = '';
        if ($slashPos !== false) {
            $head = trim(substr($raw, 0, $slashPos));
            $after = trim(substr($raw, $slashPos + 1));
            $afterFirst = $after === '' ? '' : (explode(' ', $after)[0] ?? '');
            if (strlen($afterFirst) > 8) {
                $afterFirst = substr($afterFirst, 0, 8);
            }
            $tail = $afterFirst !== '' ? ' /' . $afterFirst : '';
            $raw = $head;
        }

        $parts = preg_split('/\s+/', trim($raw));
        if ($parts === false || $parts === []) {
            return $this->clip(($raw ?: 'N/A') . $tail);
        }

        if (count($parts) === 1) {
            return $this->clip($parts[0] . $tail);
        }

        $surname = array_pop($parts);
        $initials = array_map(
            static fn (string $p) => mb_substr($p, 0, 1) . '.',
            array_slice($parts, 0, 3),
        );
        $result = implode('', $initials) . $surname . $tail;

        return $this->clip($result);
    }

    private function clip(string $s): string
    {
        if (mb_strlen($s) <= self::MAX_GUEST_CHARS) {
            return $s;
        }
        return mb_substr($s, 0, self::MAX_GUEST_CHARS - 1) . '…';
    }

    /**
     * Render the property/unit part of the row.
     *
     *  Single-property reply:  "14"                    or "10, 12, 14" for a group
     *  Mixed-property reply:   "Hotel 14"              or "Hotel 10,12,14"
     *  Unknown room:           "?"                     (never bare blank)
     *
     * @param array<int, mixed>                $roomIds
     * @param Collection<int, RoomUnitMapping> $rooms
     */
    private function formatPropertyAndUnits(array $roomIds, Collection $rooms, bool $mixedProperty): string
    {
        $units     = [];
        $propShort = null;
        foreach ($roomIds as $rid) {
            if ($rid === null) {
                $units[] = '?';
                continue;
            }
            $m = $rooms->firstWhere('room_id', (string) $rid)
              ?? $rooms->firstWhere('room_id', (int) $rid);
            if (! $m) {
                $units[] = '?';
                continue;
            }
            $units[] = (string) $m->unit_name;
            $propShort ??= $this->shortProperty((string) $m->property_name);
        }
        $unitsJoined = implode(',', array_unique($units));

        if ($mixedProperty && $propShort !== null) {
            return $propShort . ' ' . $unitsJoined;
        }
        return $unitsJoined !== '' ? $unitsJoined : '?';
    }

    /**
     * "Jahongir Hotel"   → "Hotel"
     * "Jahongir Premium" → "Prem"
     * anything else      → raw (first word)
     */
    private function shortProperty(string $name): string
    {
        $lower = strtolower($name);
        if (str_contains($lower, 'premium')) {
            return 'Prem';
        }
        if (str_contains($lower, 'hotel') || str_contains($lower, 'guest')) {
            return 'Hotel';
        }
        $first = strtok($name, ' ');
        return $first !== false ? $first : $name;
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'request'   => ' · ❓',
            'new'       => ' · 🆕',
            'cancelled' => ' · ❌',
            default     => '', // confirmed / blank → no marker
        };
    }

    /** Count N underlying bookings inside N collapsed rows. */
    private function expandedRowCount(array $rows): int
    {
        $total = 0;
        foreach ($rows as $r) {
            $total += (int) ($r['__count'] ?? 1);
        }
        return $total;
    }

    private function nights(string $arrival, string $departure): int
    {
        try {
            $a = CarbonImmutable::parse($arrival);
            $d = CarbonImmutable::parse($departure);
        } catch (\Throwable) {
            return 0;
        }
        $n = (int) $a->diffInDays($d);
        return max($n, 0);
    }

    private function shortDate(string $ymd): string
    {
        try {
            return CarbonImmutable::parse($ymd)->format('D j M');
        } catch (\Throwable) {
            return $ymd;
        }
    }
}
