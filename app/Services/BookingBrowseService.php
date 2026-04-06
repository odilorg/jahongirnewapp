<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Booking;
use Carbon\Carbon;

/**
 * Read-only service for browsing upcoming bookings in the Telegram ops bot.
 *
 * Responsibilities:
 *  - Paginated fetch of upcoming bookings (next 30 days)
 *  - Lightweight list-item DTO for Telegram button rendering
 *  - Single-booking fetch with full relations for the detail/action view
 *
 * Intentionally contains no mutation logic — all updates go through BookingOpsService.
 */
class BookingBrowseService
{
    public const PAGE_SIZE = 10;

    /**
     * Return one page of upcoming bookings.
     *
     * Ordering: pending first, then confirmed, then cancelled (if included);
     * within each group ordered by nearest booking_start_date_time.
     *
     * @return array{items: list<array{id:int,label:string}>, page:int, pages:int, total:int}
     */
    public function paginate(int $page = 1, bool $showCancelled = false): array
    {
        $statuses = $showCancelled
            ? ['pending', 'confirmed', 'cancelled']
            : ['pending', 'confirmed'];

        // Use today so bookings starting today are included.
        $from = Carbon::today();
        $to   = Carbon::today()->addDays(30)->endOfDay();

        $query = Booking::with(['tour', 'guest'])
            ->whereBetween('booking_start_date_time', [$from, $to])
            ->whereIn('booking_status', $statuses)
            ->orderByRaw(
                "CASE booking_status WHEN 'pending' THEN 0 WHEN 'confirmed' THEN 1 ELSE 2 END"
            )
            ->orderBy('booking_start_date_time');

        $total = $query->count();
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page  = max(1, min($page, $pages));

        $items = $query
            ->offset(($page - 1) * self::PAGE_SIZE)
            ->limit(self::PAGE_SIZE)
            ->get();

        return [
            'items' => $items->map(fn (Booking $b) => $this->toListItem($b))->all(),
            'page'  => $page,
            'pages' => $pages,
            'total' => $total,
        ];
    }

    /**
     * Load a single booking with all relationships needed for the action view.
     */
    public function findWithRelations(int $bookingId): ?Booking
    {
        return Booking::with(['tour', 'guest', 'driver', 'guide'])->find($bookingId);
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Build a concise one-line label for the inline-keyboard button.
     *
     * Example: "BOOK-2026-089 | 22 May | John Doe ⏳"
     * Kept under ~45 chars so Telegram renders it cleanly on a single line.
     */
    private function toListItem(Booking $booking): array
    {
        $date      = Carbon::parse($booking->booking_start_date_time)->format('d M');
        $guestName = $this->truncate($booking->guest?->full_name ?? '—', 14);
        $icon      = match ($booking->booking_status) {
            'confirmed' => '✅',
            'cancelled' => '❌',
            default     => '⏳',
        };

        return [
            'id'    => $booking->id,
            // callback_data: "brs:op:{id}" is at most 16 chars — well within 64-byte limit
            'label' => "{$booking->booking_number} | {$date} | {$guestName} {$icon}",
        ];
    }

    private function truncate(string $text, int $max): string
    {
        return mb_strlen($text) > $max
            ? mb_substr($text, 0, $max - 1) . '…'
            : $text;
    }
}
