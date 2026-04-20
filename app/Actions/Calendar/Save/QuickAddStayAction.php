<?php

declare(strict_types=1);

namespace App\Actions\Calendar\Save;

use App\Actions\Calendar\Support\CalendarActionResult;
use App\Models\Accommodation;
use App\Models\BookingInquiry;
use App\Models\InquiryStay;
use Illuminate\Support\Facades\DB;

/**
 * Add an accommodation stay from the calendar slide-over Quick-Assign panel.
 *
 * Business rules centralised here (previously inline in the page):
 *   - auto-claim inquiry if unowned
 *   - guest count falls back to inquiry->people_adults
 *   - nights defaults to 1 (enforced minimum)
 *   - stay_date falls back to inquiry->travel_date
 *   - default meal_plan = "dinner + breakfast" (matches yurt-camp standard)
 *   - cost computed via InquiryStay::calculateCost() using the active rate
 *   - sort_order = next position in the inquiry's stays list
 *
 * Transaction wrapper because the write coordinates claim + create + cost
 * calculation + save.
 */
final class QuickAddStayAction
{
    /**
     * @param  array{
     *   accommodation_id: int,
     *   guests?: ?int,
     *   nights?: ?int,
     *   date?: ?string,
     *   operator_id: int
     * } $data
     */
    public function handle(BookingInquiry $inquiry, array $data): CalendarActionResult
    {
        $accommodation = Accommodation::find($data['accommodation_id']);
        if (! $accommodation) {
            return CalendarActionResult::failure('Accommodation not found');
        }

        return DB::transaction(function () use ($inquiry, $accommodation, $data): CalendarActionResult {
            $inquiry->assignIfUnowned($data['operator_id']);

            $guests = max(1, (int) ($data['guests'] ?? $inquiry->people_adults ?? 1));
            $nights = max(1, (int) ($data['nights'] ?? 1));
            $date   = $data['date'] ?: $inquiry->travel_date?->toDateString();

            $stay = InquiryStay::create([
                'booking_inquiry_id' => $inquiry->id,
                'accommodation_id'   => $accommodation->id,
                'sort_order'         => $inquiry->stays()->count() + 1,
                'stay_date'          => $date,
                'nights'             => $nights,
                'guest_count'        => $guests,
                'meal_plan'          => 'dinner + breakfast',
            ]);

            $stay->calculateCost();
            $stay->save();

            $cost = $stay->total_accommodation_cost
                ? '$' . number_format((float) $stay->total_accommodation_cost, 2)
                : 'no rate';

            return CalendarActionResult::success(
                "Stay added: {$accommodation->name} — {$cost}",
                ['stay_id' => $stay->id],
            );
        });
    }
}
