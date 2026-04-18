<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\BookingInquiry;
use App\Models\Driver;
use App\Models\Guide;
use App\Services\DriverDispatchNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 19.1 — Booking amendment notifications.
 *
 * Watches critical fields on BookingInquiry and notifies already-dispatched
 * suppliers when they change. Silent amendments are the #1 cause of "driver
 * went to the wrong pickup" failures — this closes that gap.
 */
class BookingInquiryObserver
{
    /**
     * Fields that, when changed on an active + dispatched booking, require
     * a supplier amendment notification. Not all booking fields — only the
     * ones suppliers actually care about (time/date/place/pax).
     */
    private const AMENDMENT_FIELDS = [
        'travel_date'   => '📅 Sana',
        'pickup_time'   => '🕐 Vaqti',
        'pickup_point'  => '📍 Olib ketish joyi',
        'people_adults' => '👥 Kattalar soni',
        'people_children' => '👥 Bolalar soni',
    ];

    private const ACTIVE_STATUSES = [
        BookingInquiry::STATUS_AWAITING_PAYMENT,
        BookingInquiry::STATUS_CONFIRMED,
    ];

    /**
     * Phase 23.1 — prevent orphan cost/rate values when role is unassigned.
     * If operator clears driver_id (or guide_id) but doesn't clear the
     * associated _cost / _rate_id / _override fields, financials inflate
     * with stale data. Auto-clean on save.
     */
    public function saving(BookingInquiry $inquiry): void
    {
        if (! $inquiry->driver_id) {
            $inquiry->driver_cost                = null;
            $inquiry->driver_rate_id             = null;
            $inquiry->driver_cost_override       = false;
            $inquiry->driver_cost_override_reason = null;
        }
        if (! $inquiry->guide_id) {
            $inquiry->guide_cost                = null;
            $inquiry->guide_rate_id             = null;
            $inquiry->guide_cost_override       = false;
            $inquiry->guide_cost_override_reason = null;
        }
    }

    public function updated(BookingInquiry $inquiry): void
    {
        if (! in_array($inquiry->status, self::ACTIVE_STATUSES, true)) {
            return;
        }

        $fieldChanges  = $this->detectFieldChanges($inquiry);
        $driverChanged = $inquiry->wasChanged('driver_id');
        $guideChanged  = $inquiry->wasChanged('guide_id');

        if (empty($fieldChanges) && ! $driverChanged && ! $guideChanged) {
            return;
        }

        // Snapshot BEFORE the deferred callback runs — $inquiry->getOriginal
        // is only valid inside this observer hook's lifetime.
        $oldDriverId = $driverChanged ? $inquiry->getOriginal('driver_id') : null;
        $oldGuideId  = $guideChanged  ? $inquiry->getOriginal('guide_id')  : null;
        $newDriverId = $inquiry->driver_id;
        $newGuideId  = $inquiry->guide_id;

        // After commit to avoid notifying on rolled-back transactions.
        DB::afterCommit(function () use (
            $inquiry, $fieldChanges,
            $driverChanged, $oldDriverId, $newDriverId,
            $guideChanged,  $oldGuideId,  $newGuideId,
        ) {
            $this->auditAmendment($inquiry, $fieldChanges, $driverChanged, $guideChanged);

            $dispatcher = app(DriverDispatchNotifier::class);

            // Driver REASSIGNMENT only (X → Y). Initial assignment (null → X)
            // keeps the existing manual "Dispatch driver" flow so operator
            // has full control over the first dispatch moment.
            if ($driverChanged && $oldDriverId && $newDriverId && $oldDriverId !== $newDriverId) {
                $this->notifySupplierRemoved($dispatcher, $inquiry, 'driver', $oldDriverId);
                $dispatcher->dispatchDriver($inquiry);
            } elseif ($driverChanged && $oldDriverId && ! $newDriverId) {
                // Unassigned (X → null) — notify the removed driver
                $this->notifySupplierRemoved($dispatcher, $inquiry, 'driver', $oldDriverId);
            } elseif (! $driverChanged && $newDriverId && ! empty($fieldChanges) && $this->wasDispatched($inquiry, 'driver')) {
                // Same driver, field change → send amendment
                $dispatcher->notifyAmendment($inquiry, 'driver', $fieldChanges);
            }

            // Guide — same pattern as driver.
            if ($guideChanged && $oldGuideId && $newGuideId && $oldGuideId !== $newGuideId) {
                $this->notifySupplierRemoved($dispatcher, $inquiry, 'guide', $oldGuideId);
                $dispatcher->dispatchGuide($inquiry);
            } elseif ($guideChanged && $oldGuideId && ! $newGuideId) {
                $this->notifySupplierRemoved($dispatcher, $inquiry, 'guide', $oldGuideId);
            } elseif (! $guideChanged && $newGuideId && ! empty($fieldChanges) && $this->wasDispatched($inquiry, 'guide')) {
                $dispatcher->notifyAmendment($inquiry, 'guide', $fieldChanges);
            }
        });
    }

    /**
     * @return array<string, array{old: mixed, new: mixed, label: string}>
     */
    private function detectFieldChanges(BookingInquiry $inquiry): array
    {
        $changes = [];
        foreach (self::AMENDMENT_FIELDS as $field => $label) {
            if (! $inquiry->wasChanged($field)) {
                continue;
            }

            $old = $inquiry->getOriginal($field);
            $new = $inquiry->getAttribute($field);

            // Normalize dates for comparison/display
            if ($field === 'travel_date') {
                $old = $old ? \Carbon\Carbon::parse($old)->format('M j, Y') : '—';
                $new = $new ? $new->format('M j, Y') : '—';
            }

            $changes[$field] = [
                'old'   => (string) ($old ?? '—'),
                'new'   => (string) ($new ?? '—'),
                'label' => $label,
            ];
        }

        return $changes;
    }

    /**
     * A supplier was "dispatched" once they received their first TG about
     * this inquiry. The codebase has accumulated 3 different marker shapes
     * over time — check all of them.
     */
    private function wasDispatched(BookingInquiry $inquiry, string $role): bool
    {
        $notes = (string) $inquiry->internal_notes;
        if ($notes === '') {
            return false;
        }

        $patterns = [
            "Calendar dispatch TG → {$role}",    // slide-over dispatch (current)
            "Dispatch TG → {$role}",             // older resource action shape
            ucfirst($role) . ' dispatch sent',   // what Phase 17 cancellation marker writes
            "{$role} dispatched",                // generic fallback
        ];

        foreach ($patterns as $p) {
            if (str_contains($notes, $p) || str_contains(strtolower($notes), strtolower($p))) {
                return true;
            }
        }

        return false;
    }

    private function notifySupplierRemoved(
        DriverDispatchNotifier $dispatcher,
        BookingInquiry $inquiry,
        string $role,
        int $oldSupplierId,
    ): void {
        $supplier = $role === 'driver'
            ? Driver::find($oldSupplierId)
            : Guide::find($oldSupplierId);

        if (! $supplier) {
            return;
        }

        try {
            $dispatcher->notifySupplierRemoved($inquiry, $role, $supplier);
        } catch (\Throwable $e) {
            Log::warning('BookingInquiryObserver: notifySupplierRemoved failed', [
                'inquiry_id' => $inquiry->id,
                'role'       => $role,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    private function auditAmendment(
        BookingInquiry $inquiry,
        array $fieldChanges,
        bool $driverChanged,
        bool $guideChanged,
    ): void {
        $lines = ['Amendment:'];

        foreach ($fieldChanges as $field => $c) {
            $lines[] = "  - {$field}: {$c['old']} → {$c['new']}";
        }

        if ($driverChanged) {
            $lines[] = "  - driver reassigned";
        }
        if ($guideChanged) {
            $lines[] = "  - guide reassigned";
        }

        $lines[] = "  By: " . (auth()->user()?->name ?? 'system');

        $timestamp = now()->format('Y-m-d H:i');
        $existing  = $inquiry->internal_notes ?? '';
        $separator = $existing ? "\n\n" : '';

        // saveQuietly to avoid triggering this observer recursively.
        $inquiry->internal_notes = $existing . $separator . "[{$timestamp}] " . implode("\n", $lines);
        $inquiry->saveQuietly();
    }
}
