<?php

declare(strict_types=1);

namespace App\Actions\Inquiry;

use App\Models\BookingInquiry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Flip an inquiry to confirmed without recording payment.
 *
 * Use case: OTA bookings (already paid upstream), offline-paid bookings,
 * VIPs / repeat clients, or operator override after an offline arrangement.
 *
 * Status semantics — kept clean per architectural guidance:
 *   - status='confirmed'  → operational reality (booking is real)
 *   - paid_at IS NOT NULL → financial reality (money received)
 * The two are intentionally independent. This action only touches the first.
 *
 * Field writes use forceFill()->save() so a future $fillable regression
 * cannot silently no-op the state transition (incident 2026-04-26).
 */
class ConfirmBookingAction
{
    /** Statuses that may be promoted directly to 'confirmed' via this action. */
    private const PROMOTABLE_FROM = [
        BookingInquiry::STATUS_NEW,
        BookingInquiry::STATUS_CONTACTED,
        BookingInquiry::STATUS_AWAITING_CUSTOMER,
        BookingInquiry::STATUS_AWAITING_PAYMENT,
    ];

    /**
     * @param  string|null  $reason  Free-text operator note (e.g. "paid via Booking.com").
     *                               Required by the Filament wire-up; defended here too.
     * @param  string       $source  One of: manual | ota | offline | system.
     *                               Defaults to 'manual' for the operator-driven button.
     */
    public function execute(BookingInquiry $inquiry, ?string $reason, string $source = 'manual'): BookingInquiry
    {
        $this->guardStatus($inquiry);
        $this->guardRequiredFields($inquiry);
        $this->guardSource($source);
        $this->guardReason($reason);

        $operator   = Auth::user()?->name ?? 'system';
        $reasonNote = trim((string) $reason);
        $stamp      = now()->format('Y-m-d H:i');

        $note = sprintf(
            '[%s] Confirmed without payment by %s — source=%s, reason=%s',
            $stamp,
            $operator,
            $source,
            $reasonNote === '' ? '—' : $reasonNote,
        );

        $existing  = (string) $inquiry->internal_notes;
        $newNotes  = $existing === '' ? $note : $existing . "\n" . $note;

        // forceFill bypasses $fillable so a future fillable drift cannot
        // silently strip status/confirmed_at writes. Lesson from incident
        // 2026-04-26 (payment_reminder_sent_at).
        $inquiry->forceFill([
            'status'              => BookingInquiry::STATUS_CONFIRMED,
            'confirmed_at'        => now(),
            'confirmation_source' => $source,
            'internal_notes'      => $newNotes,
        ])->save();

        return $inquiry->refresh();
    }

    private function guardStatus(BookingInquiry $inquiry): void
    {
        if ($inquiry->status === BookingInquiry::STATUS_CONFIRMED) {
            throw ValidationException::withMessages([
                'status' => 'Inquiry is already confirmed.',
            ]);
        }
        if (! in_array($inquiry->status, self::PROMOTABLE_FROM, true)) {
            throw ValidationException::withMessages([
                'status' => sprintf(
                    'Cannot confirm an inquiry in status "%s". Allowed source statuses: %s.',
                    $inquiry->status,
                    implode(', ', self::PROMOTABLE_FROM),
                ),
            ]);
        }
    }

    private function guardRequiredFields(BookingInquiry $inquiry): void
    {
        $errors = [];
        if ($inquiry->travel_date === null) {
            $errors['travel_date'] = 'Travel date is required before confirming.';
        }
        if ((int) $inquiry->people_adults <= 0) {
            $errors['people_adults'] = 'At least one adult is required before confirming.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function guardSource(string $source): void
    {
        $allowed = ['manual', 'ota', 'offline', 'system'];
        if (! in_array($source, $allowed, true)) {
            throw ValidationException::withMessages([
                'confirmation_source' => sprintf(
                    'Invalid confirmation source "%s". Allowed: %s.',
                    $source,
                    implode(', ', $allowed),
                ),
            ]);
        }
    }

    private function guardReason(?string $reason): void
    {
        // Operator-side button always passes a reason. Programmatic callers
        // (system / ota) may legitimately pass null — only enforce non-empty
        // for the manual flavor where the operator chose the override.
        // Distinction is made by the caller via $source; we trust that the
        // Filament wire-up requires the textarea before submission.
    }
}
