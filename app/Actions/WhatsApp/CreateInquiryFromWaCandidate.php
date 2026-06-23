<?php

declare(strict_types=1);

namespace App\Actions\WhatsApp;

use App\Models\BookingInquiry;
use App\Models\WaLeadCandidate;
use Illuminate\Support\Facades\DB;

/**
 * Create a booking_inquiry from an operator-reviewed WhatsApp candidate. This is
 * OPERATOR-INITIATED (a button click) — NOT the gated auto-create (Phase 2d).
 * Idempotent + dedup-safe:
 *   - candidate already linked to an inquiry -> return it, create nothing
 *   - phone already an in-flight inquiry      -> link the candidate to it, create nothing
 *   - otherwise                               -> create one inquiry, link the candidate
 * No price guessing, no payment link, no booking confirmation, no guest send.
 */
class CreateInquiryFromWaCandidate
{
    /** @return array{inquiry: BookingInquiry, created: bool, reason: ?string} */
    public function create(WaLeadCandidate $candidate, string $by): array
    {
        if ($candidate->booking_inquiry_id !== null && $candidate->bookingInquiry) {
            return ['inquiry' => $candidate->bookingInquiry, 'created' => false, 'reason' => 'already_created'];
        }

        $phone = BookingInquiry::normalizePhone($candidate->phone);
        $dups = BookingInquiry::findInFlightDuplicates($phone, null);
        if ($dups->isNotEmpty()) {
            $existing = $dups->first();
            $this->link($candidate, $existing->id, $by);

            return ['inquiry' => $existing, 'created' => false, 'reason' => 'linked_existing'];
        }

        $inquiry = DB::transaction(function () use ($candidate, $by): BookingInquiry {
            $inquiry = BookingInquiry::create([
                'reference'          => BookingInquiry::generateReference(),
                'source'             => BookingInquiry::SOURCE_WHATSAPP,
                'status'             => BookingInquiry::STATUS_NEW,
                'customer_name'      => 'WhatsApp guest',           // no name captured yet; operator edits
                'customer_email'     => null,
                'customer_phone'     => mb_substr((string) $candidate->phone, 0, 64),
                'tour_name_snapshot' => $candidate->detected_tour
                    ? mb_substr($candidate->detected_tour, 0, 255)
                    : 'WhatsApp inquiry',
                'travel_date'        => $candidate->detected_date,  // only if classifier was confident
                'people_adults'      => $candidate->detected_party_size && $candidate->detected_party_size > 0
                    ? min($candidate->detected_party_size, 99) : 1,
                'people_children'    => 0,
                'submitted_at'       => $candidate->last_inbound_at ?? now(),
                'message'            => $this->auditMessage($candidate, $by),
            ]);
            $this->link($candidate, $inquiry->id, $by);

            return $inquiry;
        });

        return ['inquiry' => $inquiry, 'created' => true, 'reason' => null];
    }

    private function link(WaLeadCandidate $candidate, int $inquiryId, string $by): void
    {
        $candidate->forceFill([
            'status'             => WaLeadCandidate::STATUS_CREATED,
            'booking_inquiry_id' => $inquiryId,
            'decided_by'         => $by,
            'decided_at'         => now(),
        ])->save();
    }

    private function auditMessage(WaLeadCandidate $c, string $by): string
    {
        $note = sprintf(
            "[Created from WhatsApp prospect #%d by %s | classifier: %s/%s conf %s]\n\n",
            $c->id, $by, $c->classification ?? 'n/a', $c->not_lead_subtype ?? '-', $c->confidence ?? '-'
        );

        return $note . (string) ($c->first_messages ?? $c->first_inbound ?? '');
    }
}
