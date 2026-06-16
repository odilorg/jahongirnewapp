<?php

declare(strict_types=1);

namespace App\Actions\Inquiry;

use App\Models\BookingInquiry;
use Illuminate\Validation\ValidationException;

/**
 * Canonical guarded commercial-status transition for the tour-agent path.
 *
 * Covers the safe, internal Tier-1 transitions only:
 *   contacted | awaiting_customer | awaiting_payment | spam | cancelled
 *
 * It deliberately does NOT handle 'confirmed' (that is ConfirmBookingAction,
 * which has its own payment/calendar side effects) nor 'new'. Each target
 * declares which source statuses may reach it — the agent's safety rail
 * against nonsensical jumps (e.g. contacting a cancelled lead). Sets the
 * matching *_at stamp via forceFill and appends one audit line.
 *
 * NOTE: the Filament resource currently performs these transitions inline.
 * Converging those closures onto this Action is a tracked follow-up; for now
 * this is the single source of truth for the AGENT path only.
 */
class TransitionInquiryStatusAction
{
    /**
     * target => [allowed source statuses, timestamp column to stamp (or null)]
     *
     * @var array<string,array{from:list<string>,ts:?string}>
     */
    private const TRANSITIONS = [
        BookingInquiry::STATUS_CONTACTED => [
            'from' => [BookingInquiry::STATUS_NEW, BookingInquiry::STATUS_AWAITING_CUSTOMER],
            'ts' => 'contacted_at',
        ],
        BookingInquiry::STATUS_AWAITING_CUSTOMER => [
            'from' => [BookingInquiry::STATUS_NEW, BookingInquiry::STATUS_CONTACTED, BookingInquiry::STATUS_AWAITING_PAYMENT],
            'ts' => null,
        ],
        BookingInquiry::STATUS_AWAITING_PAYMENT => [
            'from' => [BookingInquiry::STATUS_NEW, BookingInquiry::STATUS_CONTACTED, BookingInquiry::STATUS_AWAITING_CUSTOMER],
            'ts' => null,
        ],
        BookingInquiry::STATUS_SPAM => [
            'from' => [BookingInquiry::STATUS_NEW, BookingInquiry::STATUS_CONTACTED],
            'ts' => null,
        ],
        BookingInquiry::STATUS_CANCELLED => [
            'from' => [
                BookingInquiry::STATUS_NEW, BookingInquiry::STATUS_CONTACTED,
                BookingInquiry::STATUS_AWAITING_CUSTOMER, BookingInquiry::STATUS_AWAITING_PAYMENT,
            ],
            'ts' => 'cancelled_at',
        ],
    ];

    public function execute(BookingInquiry $inquiry, string $target, string $actor = 'tour-agent', ?string $note = null): BookingInquiry
    {
        // Idempotent: already in the target status → no-op, no duplicate note.
        if ($inquiry->status === $target) {
            return $inquiry;
        }

        $this->assertCanTransition($inquiry, $target);

        $from = $inquiry->status;
        $audit = "Status {$from} → {$target}".($note !== null && trim($note) !== '' ? ' — '.trim($note) : '');

        $writes = ['status' => $target];
        $tsColumn = self::TRANSITIONS[$target]['ts'];
        if ($tsColumn !== null && $inquiry->{$tsColumn} === null) {
            $writes[$tsColumn] = now();
        }

        $existing = (string) $inquiry->internal_notes;
        $line = AppendInquiryNoteAction::formatLine($audit, $actor);
        $writes['internal_notes'] = $existing === '' ? $line : $existing."\n".$line;

        $inquiry->forceFill($writes)->save();

        return $inquiry->refresh();
    }

    /** Throws ValidationException if $target is unknown or unreachable from the current status. */
    public function assertCanTransition(BookingInquiry $inquiry, string $target): void
    {
        if (! array_key_exists($target, self::TRANSITIONS)) {
            throw ValidationException::withMessages([
                'status' => sprintf('Status "%s" is not an agent Tier-1 transition.', $target),
            ]);
        }

        if (! in_array($inquiry->status, self::TRANSITIONS[$target]['from'], true)) {
            throw ValidationException::withMessages([
                'status' => sprintf(
                    'Cannot move inquiry from "%s" to "%s". Allowed source statuses: %s.',
                    $inquiry->status,
                    $target,
                    implode(', ', self::TRANSITIONS[$target]['from']),
                ),
            ]);
        }
    }
}
