<?php

declare(strict_types=1);

namespace App\Actions\Calendar\Save;

use App\Actions\Calendar\Support\CalendarActionResult;
use App\Models\BookingInquiry;

/**
 * Save pickup time + location from the slide-over.
 * Empty strings are normalised to NULL so an operator can clear the
 * fields without leaving stale data behind.
 */
final class QuickSavePickupAction
{
    /**
     * @param  array{time?: ?string, point?: ?string, operator_id: int}  $data
     */
    public function handle(BookingInquiry $inquiry, array $data): CalendarActionResult
    {
        $inquiry->assignIfUnowned($data['operator_id']);

        $inquiry->update([
            'pickup_time'  => ($data['time'] ?? null) ?: null,
            'pickup_point' => ($data['point'] ?? null) ?: null,
        ]);

        return CalendarActionResult::success('Pickup info saved');
    }
}
