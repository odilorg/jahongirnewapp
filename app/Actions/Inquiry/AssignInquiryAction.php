<?php

declare(strict_types=1);

namespace App\Actions\Inquiry;

use App\Models\BookingInquiry;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Assign an operator to an inquiry. Delegates to the model's
 * assignIfUnowned() so first-touch ownership is honoured (an existing
 * assignment is never overwritten) — single source of truth for that rule.
 */
class AssignInquiryAction
{
    public function execute(BookingInquiry $inquiry, int $userId): BookingInquiry
    {
        if ($userId <= 0 || ! User::whereKey($userId)->exists()) {
            throw ValidationException::withMessages([
                'user_id' => sprintf('User %d not found.', $userId),
            ]);
        }

        $inquiry->assignIfUnowned($userId);

        return $inquiry->refresh();
    }
}
