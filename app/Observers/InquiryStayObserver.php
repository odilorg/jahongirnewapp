<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Accommodation;
use App\Models\InquiryStay;
use App\Services\DriverDispatchNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 19.3a — Accommodation amendment notifications.
 *
 * Watches critical fields on InquiryStay and notifies the accommodation
 * supplier if a change happens AFTER they've already been dispatched.
 * Guards strictly: no dispatch marker = no notification.
 */
class InquiryStayObserver
{
    /**
     * Accommodation cares about: when, how many, how long, what meals.
     * Does NOT care about driver/guide/pickup (those are BookingInquiry-level).
     */
    private const AMENDMENT_FIELDS = [
        'stay_date'   => '📅 Sana',
        'guest_count' => '👥 Mehmonlar',
        'nights'      => '🌙 Tunlar',
        'meal_plan'   => '🍽 Ovqat',
    ];

    public function updated(InquiryStay $stay): void
    {
        // Only notify on active bookings
        $inquiry = $stay->inquiry;
        if (! $inquiry) {
            return;
        }
        if (! in_array($inquiry->status, [
            'awaiting_payment',
            'confirmed',
        ], true)) {
            return;
        }

        $fieldChanges = $this->detectFieldChanges($stay);
        $accChanged   = $stay->wasChanged('accommodation_id');

        if (empty($fieldChanges) && ! $accChanged) {
            return;
        }

        // Snapshot originals BEFORE deferred callback
        $oldAccId = $accChanged ? $stay->getOriginal('accommodation_id') : null;
        $newAccId = $stay->accommodation_id;

        DB::afterCommit(function () use ($stay, $inquiry, $fieldChanges, $accChanged, $oldAccId, $newAccId) {
            $this->auditAmendment($stay, $fieldChanges, $accChanged);

            $dispatcher = app(DriverDispatchNotifier::class);

            // Reassignment: old accommodation removed, new fresh-dispatched
            if ($accChanged && $oldAccId && $newAccId && $oldAccId !== $newAccId) {
                $oldAcc = Accommodation::find($oldAccId);
                if ($oldAcc) {
                    $this->notifyRemoved($dispatcher, $inquiry, $stay, $oldAcc);
                }
                // New accommodation: full fresh dispatch
                $dispatcher->dispatchStay($inquiry, $stay);
                return;
            }

            // Unassignment: accommodation removed entirely
            if ($accChanged && $oldAccId && ! $newAccId) {
                $oldAcc = Accommodation::find($oldAccId);
                if ($oldAcc) {
                    $this->notifyRemoved($dispatcher, $inquiry, $stay, $oldAcc);
                }
                return;
            }

            // Initial assignment (null → X): no auto-dispatch (operator controls)
            if ($accChanged && ! $oldAccId && $newAccId) {
                return;
            }

            // Same accommodation + field change → amendment (STRICT dispatched guard)
            if (! empty($fieldChanges) && $newAccId && $this->wasDispatched($stay)) {
                $dispatcher->notifyStayAmendment($inquiry, $stay, $fieldChanges);
            }
        });
    }

    /**
     * @return array<string, array{old: string, new: string, label: string}>
     */
    private function detectFieldChanges(InquiryStay $stay): array
    {
        $changes = [];
        foreach (self::AMENDMENT_FIELDS as $field => $label) {
            if (! $stay->wasChanged($field)) {
                continue;
            }

            $old = $stay->getOriginal($field);
            $new = $stay->getAttribute($field);

            if ($field === 'stay_date') {
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
     * Accommodation was "dispatched" if internal_notes contains the marker.
     * Pattern matches both Calendar slide-over dispatches and the Resource
     * page accommodation dispatch action.
     */
    private function wasDispatched(InquiryStay $stay): bool
    {
        $notes = (string) $stay->inquiry?->internal_notes;
        if ($notes === '') {
            return false;
        }

        $accName = $stay->accommodation?->name;
        if (! $accName) {
            return false;
        }

        // Match "Calendar dispatch TG → stay <name>" patterns.
        // Be flexible — real logs have double spaces sometimes.
        return str_contains($notes, "Calendar dispatch TG → stay {$accName}")
            || str_contains($notes, "dispatch TG → stay {$accName}")
            || preg_match('/dispatch.*stay.*' . preg_quote($accName, '/') . '/i', $notes) === 1;
    }

    private function notifyRemoved(DriverDispatchNotifier $dispatcher, $inquiry, InquiryStay $stay, Accommodation $oldAcc): void
    {
        try {
            $dispatcher->notifyStayRemoved($inquiry, $stay, $oldAcc);
        } catch (\Throwable $e) {
            Log::warning('InquiryStayObserver: notifyStayRemoved failed', [
                'stay_id' => $stay->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    private function auditAmendment(InquiryStay $stay, array $fieldChanges, bool $accChanged): void
    {
        $lines = ['Stay amendment:'];
        foreach ($fieldChanges as $field => $c) {
            $lines[] = "  - {$field}: {$c['old']} → {$c['new']}";
        }
        if ($accChanged) {
            $lines[] = "  - accommodation reassigned";
        }
        $lines[] = "  By: " . (auth()->user()?->name ?? 'system');

        $inquiry = $stay->inquiry;
        if (! $inquiry) {
            return;
        }

        $timestamp = now()->format('Y-m-d H:i');
        $existing  = $inquiry->internal_notes ?? '';
        $separator = $existing ? "\n\n" : '';

        $inquiry->internal_notes = $existing . $separator . "[{$timestamp}] " . implode("\n", $lines);
        $inquiry->saveQuietly();
    }
}
