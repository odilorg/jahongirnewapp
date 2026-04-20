<?php

declare(strict_types=1);

namespace App\Actions\Calendar\Dispatch;

use App\Actions\Calendar\Support\CalendarActionResult;
use App\Models\BookingInquiry;
use App\Services\DriverDispatchNotifier;

/**
 * Send the driver a Telegram DM with the full tour dispatch card.
 *
 * Business rules enforced here (moved out of the Filament page):
 *   - inquiry must be dispatchable (isDispatchable)
 *   - a driver must be assigned
 *
 * Side effects: DriverDispatchNotifier stamps driver_dispatched_at and
 * appends an audit line to internal_notes in a single query-builder
 * update (preserves updated_at semantics).
 */
final class DispatchDriverAction
{
    public function __construct(
        private readonly DriverDispatchNotifier $notifier,
    ) {}

    public function handle(BookingInquiry $inquiry): CalendarActionResult
    {
        if (! $inquiry->isDispatchable()) {
            return CalendarActionResult::failure('Inquiry is not in a dispatchable status');
        }

        if (! $inquiry->driver_id) {
            return CalendarActionResult::failure('No driver assigned');
        }

        $result = $this->notifier->dispatchSupplier($inquiry, 'driver');

        if (! ($result['ok'] ?? false)) {
            return CalendarActionResult::failure(
                $result['reason'] ?? 'Driver dispatch failed',
                $result,
            );
        }

        return CalendarActionResult::success(
            'Driver dispatch sent',
            ['msg_id' => $result['msg_id'] ?? null],
        );
    }
}
