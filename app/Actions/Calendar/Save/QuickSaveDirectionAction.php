<?php

declare(strict_types=1);

namespace App\Actions\Calendar\Save;

use App\Actions\Calendar\Support\CalendarActionResult;
use App\Models\BookingInquiry;

/**
 * Save tour direction (e.g. Bukhara → Samarkand) from the slide-over.
 * Empty → NULL so the direction can be cleared without a separate flow.
 */
final class QuickSaveDirectionAction
{
    /**
     * @param  array{direction_id?: ?int, operator_id: int}  $data
     */
    public function handle(BookingInquiry $inquiry, array $data): CalendarActionResult
    {
        $inquiry->assignIfUnowned($data['operator_id']);

        $inquiry->update([
            'tour_product_direction_id' => ($data['direction_id'] ?? null) ?: null,
        ]);

        return CalendarActionResult::success('Direction saved');
    }
}
