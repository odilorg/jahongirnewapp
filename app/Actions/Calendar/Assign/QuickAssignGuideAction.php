<?php

declare(strict_types=1);

namespace App\Actions\Calendar\Assign;

use App\Actions\Calendar\Support\CalendarActionResult;
use App\Models\BookingInquiry;
use App\Models\Guide;
use App\Models\GuideRate;
use Illuminate\Support\Facades\DB;

/**
 * Parallel of QuickAssignDriverAction for guides. Kept separate per
 * architecture principle "one Action per user intent" — gating and rate
 * semantics can diverge later without churn.
 */
final class QuickAssignGuideAction
{
    /**
     * @param  array{rate_id?: int|null, operator_id: int}  $data
     */
    public function handle(BookingInquiry $inquiry, Guide $guide, array $data): CalendarActionResult
    {
        return DB::transaction(function () use ($inquiry, $guide, $data): CalendarActionResult {
            $inquiry->assignIfUnowned($data['operator_id']);

            $update = ['guide_id' => $guide->id];

            if (! empty($data['rate_id'])) {
                $rate = GuideRate::find($data['rate_id']);
                if ($rate && $rate->guide_id === $guide->id) {
                    $update['guide_rate_id'] = $rate->id;
                    $update['guide_cost']    = $rate->cost_usd;
                }
            }

            $inquiry->update($update);

            return CalendarActionResult::success('Guide assigned');
        });
    }
}
