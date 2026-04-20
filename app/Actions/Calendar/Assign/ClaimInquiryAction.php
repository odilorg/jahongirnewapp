<?php

declare(strict_types=1);

namespace App\Actions\Calendar\Assign;

use App\Actions\Calendar\Support\CalendarActionResult;
use App\Models\BookingInquiry;

/**
 * Operator claims an UNOWNED inquiry for themselves from the slide-over.
 * No-op (idempotent) if already owned — callers expect no UI disturbance
 * when racing two operators clicking Claim near-simultaneously.
 */
final class ClaimInquiryAction
{
    public function handle(BookingInquiry $inquiry, int $operatorId): CalendarActionResult
    {
        if ($inquiry->assigned_to_user_id) {
            return CalendarActionResult::failure('Lead already claimed');
        }

        $inquiry->update(['assigned_to_user_id' => $operatorId]);

        return CalendarActionResult::success('Lead claimed');
    }
}
