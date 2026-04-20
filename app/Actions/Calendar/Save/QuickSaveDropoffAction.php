<?php

declare(strict_types=1);

namespace App\Actions\Calendar\Save;

use App\Actions\Calendar\Support\CalendarActionResult;
use App\Models\BookingInquiry;

/**
 * Save drop-off location from the slide-over. Location-only — no
 * dropoff_time column exists; drop-off timing is implicit (end of tour).
 */
final class QuickSaveDropoffAction
{
    /**
     * @param  array{point?: ?string, operator_id: int}  $data
     */
    public function handle(BookingInquiry $inquiry, array $data): CalendarActionResult
    {
        $inquiry->assignIfUnowned($data['operator_id']);

        $inquiry->update([
            'dropoff_point' => ($data['point'] ?? null) ?: null,
        ]);

        return CalendarActionResult::success('Drop-off saved');
    }
}
