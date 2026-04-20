<?php

declare(strict_types=1);

namespace App\Actions\Calendar\Assign;

use App\Actions\Calendar\Support\CalendarActionResult;
use App\Models\BookingInquiry;
use App\Models\Driver;
use App\Models\DriverRate;
use Illuminate\Support\Facades\DB;

/**
 * Assign a driver (and optional rate) to an inquiry from the calendar
 * slide-over's Quick-Assign panel.
 *
 * Side effects:
 *   - auto-claims the inquiry for the current operator if unowned
 *   - when a rate is provided, denormalises driver_cost onto the inquiry
 *     so the dispatch UI / financials don't need to re-lookup
 *
 * Wrapped in a transaction because three coordinated writes can happen
 * (claim, driver assign, cost denormalise) — rule #??? in PRINCIPLES.md.
 */
final class QuickAssignDriverAction
{
    /**
     * @param  array{rate_id?: int|null, operator_id: int}  $data
     */
    public function handle(BookingInquiry $inquiry, Driver $driver, array $data): CalendarActionResult
    {
        return DB::transaction(function () use ($inquiry, $driver, $data): CalendarActionResult {
            $inquiry->assignIfUnowned($data['operator_id']);

            $update = ['driver_id' => $driver->id];

            if (! empty($data['rate_id'])) {
                $rate = DriverRate::find($data['rate_id']);
                if ($rate && $rate->driver_id === $driver->id) {
                    $update['driver_rate_id'] = $rate->id;
                    $update['driver_cost']    = $rate->cost_usd;
                }
            }

            $inquiry->update($update);

            return CalendarActionResult::success('Driver assigned');
        });
    }
}
