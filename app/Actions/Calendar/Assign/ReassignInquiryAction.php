<?php

declare(strict_types=1);

namespace App\Actions\Calendar\Assign;

use App\Actions\Calendar\Support\CalendarActionResult;
use App\Models\BookingInquiry;
use App\Models\User;

/**
 * Reassign an inquiry to another operator — or clear assignment entirely
 * if target_user_id is null. Surfaces the new assignee's display name so
 * the page can show it in the success notification.
 */
final class ReassignInquiryAction
{
    public function handle(BookingInquiry $inquiry, ?int $targetUserId): CalendarActionResult
    {
        $inquiry->update(['assigned_to_user_id' => $targetUserId ?: null]);

        $name = $targetUserId
            ? (User::find($targetUserId)?->name ?? 'user')
            : 'unassigned';

        return CalendarActionResult::success("Reassigned to {$name}");
    }
}
