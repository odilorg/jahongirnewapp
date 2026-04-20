<?php

declare(strict_types=1);

namespace App\Actions\Calendar\Dispatch;

use App\Actions\Calendar\Support\CalendarActionResult;
use App\Models\BookingInquiry;
use App\Services\DriverDispatchNotifier;

/**
 * Dispatch ALL stays on an inquiry to their respective accommodations.
 *
 * The user-facing button in the slide-over is singular ("Accom."), so the
 * Action is intent-centered on BookingInquiry even though the underlying
 * notifier operates per-stay. Aggregate results (ok/fail counts, partial
 * failures) are surfaced via the CalendarActionResult payload.
 *
 * A future per-stay re-dispatch control (e.g. click a single stay to
 * resend) would get its own sibling Action (`DispatchStayAction`) rather
 * than overloading this one.
 */
final class DispatchAccommodationAction
{
    public function __construct(
        private readonly DriverDispatchNotifier $notifier,
    ) {}

    public function handle(BookingInquiry $inquiry): CalendarActionResult
    {
        if (! $inquiry->isDispatchable()) {
            return CalendarActionResult::failure('Inquiry is not in a dispatchable status');
        }

        $stays = $inquiry->stays;
        if ($stays->isEmpty()) {
            return CalendarActionResult::failure('No stays to dispatch');
        }

        $ok = 0;
        $fail = 0;
        $perStay = [];

        foreach ($stays as $stay) {
            if (! $stay->accommodation) {
                $fail++;
                $perStay[] = ['stay_id' => $stay->id, 'ok' => false, 'reason' => 'no_accommodation'];
                continue;
            }

            $result = $this->notifier->dispatchStay($inquiry, $stay);
            if ($result['ok'] ?? false) {
                $ok++;
                $perStay[] = ['stay_id' => $stay->id, 'ok' => true, 'msg_id' => $result['msg_id'] ?? null];
            } else {
                $fail++;
                $perStay[] = ['stay_id' => $stay->id, 'ok' => false, 'reason' => $result['reason'] ?? 'unknown'];
            }
        }

        if ($fail === 0 && $ok > 0) {
            return CalendarActionResult::success(
                "Accommodation dispatch sent ({$ok})",
                ['ok' => $ok, 'fail' => $fail, 'stays' => $perStay],
            );
        }

        if ($ok > 0) {
            return CalendarActionResult::failure(
                "Partial: {$ok} sent, {$fail} failed",
                ['ok' => $ok, 'fail' => $fail, 'stays' => $perStay, 'partial' => true],
            );
        }

        return CalendarActionResult::failure(
            'Accommodation dispatch failed',
            ['ok' => $ok, 'fail' => $fail, 'stays' => $perStay],
        );
    }
}
