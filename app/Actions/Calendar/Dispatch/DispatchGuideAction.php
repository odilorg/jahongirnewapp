<?php

declare(strict_types=1);

namespace App\Actions\Calendar\Dispatch;

use App\Actions\Calendar\Support\CalendarActionResult;
use App\Models\BookingInquiry;
use App\Services\DriverDispatchNotifier;

/**
 * Send the guide a Telegram DM with the full tour dispatch card.
 * Structurally parallel to DispatchDriverAction — kept separate per
 * architecture principle "one Action per user intent" so gating and
 * side effects can diverge later without touching sibling actions.
 */
final class DispatchGuideAction
{
    public function __construct(
        private readonly DriverDispatchNotifier $notifier,
    ) {}

    public function handle(BookingInquiry $inquiry): CalendarActionResult
    {
        if (! $inquiry->isDispatchable()) {
            return CalendarActionResult::failure('Inquiry is not in a dispatchable status');
        }

        if (! $inquiry->guide_id) {
            return CalendarActionResult::failure('No guide assigned');
        }

        $result = $this->notifier->dispatchSupplier($inquiry, 'guide');

        if (! ($result['ok'] ?? false)) {
            return CalendarActionResult::failure(
                $result['reason'] ?? 'Guide dispatch failed',
                $result,
            );
        }

        return CalendarActionResult::success(
            'Guide dispatch sent',
            ['msg_id' => $result['msg_id'] ?? null],
        );
    }
}
