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
 * Phase 9 behaviors:
 *   - Header "{title} ({count} found)"
 *   - Sort ascending by the mode's relevant date (arrival for default/
 *     arrivals, departure for departures)
 *   - Group by that relevant date (one heading per date)
 *   - Cap at config('hotel_booking_bot.view.max_rows') rows; overflow
 *     gets a "+X more (narrow your query)" footer
 *   - Empty-state copy: "No bookings found for {title}."
 *
 * Mode is one of:
 *   - 'arrivals'   → sort + group by arrival date
 *   - 'departures' → sort + group by departure date
 *   - 'stays'      → default (stays-overlap query); sort + group by arrival
 *   - 'none'       → no grouping, fall back to single flat list sorted by arrival
 */
final class BookingListFormatter
{
    public const MODE_ARRIVALS   = 'arrivals';
    public const MODE_DEPARTURES = 'departures';
    public const MODE_STAYS      = 'stays';
    public const MODE_NONE       = 'none';

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

        $shown    = array_slice($bookings, 0, $maxRows);
        $overflow = max(0, $count - $maxRows);

        $hasMixedProperty = $shown
            ? count(array_unique(array_map(static fn ($b) => (string) ($b['propertyId'] ?? ''), $shown))) > 1
            : false;

        $header = "{$title} ({$count} found)";

        if ($mode === self::MODE_NONE) {
            $body = $this->renderFlat($shown, $rooms, $hasMixedProperty);
        } else {
            $body = $this->renderGrouped($shown, $rooms, $sortKey, $hasMixedProperty);
        }

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
            $heading = $this->humanDate($date);
            $out .= "— {$heading} —\n";
            foreach ($rows as $b) {
                $out .= $this->renderRow($b, $rooms, $mixedProperty) . "\n";
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
     * One booking line. Compact so 30 rows fit in one Telegram message.
     */
    private function renderRow(array $b, Collection $rooms, bool $mixedProperty): string
    {
        $id    = (string) ($b['id'] ?? '?');
        $guest = trim(((string) ($b['firstName'] ?? '')) . ' ' . ((string) ($b['lastName'] ?? '')));
        if ($guest === '') {
            $guest = 'N/A';
        }

        $unit = 'N/A';
        if (isset($b['roomId'])) {
            $mapping = $rooms->firstWhere('room_id', (string) $b['roomId'])
                ?? $rooms->firstWhere('room_id', (int) $b['roomId']);
            if ($mapping) {
                $unit = $mixedProperty
                    ? $mapping->property_name . ' · ' . $mapping->unit_name . ' — ' . $mapping->room_name
                    : $mapping->unit_name . ' — ' . $mapping->room_name;
            }
        }

        $nights = $this->nights((string) ($b['arrival'] ?? ''), (string) ($b['departure'] ?? ''));
        $status = (string) ($b['status'] ?? '');
        $statusMark = match ($status) {
            'confirmed' => '✅',
            'request'   => '❓',
            'cancelled' => '❌',
            'new'       => '🆕',
            default     => '',
        };
        $statusLabel = $status !== '' ? ' ' . $statusMark . ' ' . ucfirst($status) : '';

        return "#{$id} · {$guest} · {$unit} · {$nights}n{$statusLabel}";
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

    private function humanDate(string $ymd): string
    {
        try {
            return CarbonImmutable::parse($ymd)->format('D, j M Y');
        } catch (\Throwable) {
            return $ymd;
        }
    }
}
