<?php

declare(strict_types=1);

namespace App\Actions\BookingInquiries;

use App\Models\BookingInquiry;
use App\Models\GmailLeadIngestion;
use App\Services\Gmail\GmailInboundEmail;
use App\Services\Gmail\GmailLeadQualifier;
use Illuminate\Support\Facades\DB;

/**
 * Ingests ONE qualifying Gmail message as a booking_inquiry. Decision tree
 * (every branch records exactly one ledger row, biased to skip-not-create):
 *   already in ledger      -> already_processed (no new row)
 *   not a lead / blocklist -> skipped_* (ledger only)
 *   in-flight duplicate    -> skipped_duplicate_inquiry (ledger, links existing)
 *   otherwise              -> create inquiry + ledger row in one transaction
 *
 * Read-only/qualification lives in GmailLeadQualifier; this action owns the DB
 * effects only. The caller (command) handles mailbox mutation AFTER a created
 * result. Returns an array: {decision, move, inquiry_id, ingestion_id}.
 */
class IngestGmailEmailAsInquiry
{
    public function __construct(private GmailLeadQualifier $qualifier)
    {
    }

    /** @return array{decision: string, move: bool, inquiry_id: ?int, ingestion_id: ?int} */
    public function ingest(GmailInboundEmail $email): array
    {
        $messageId = $email->messageId
            ?? 'sha256:' . hash('sha256', $email->subject . '|' . $email->envelopeId);

        if (GmailLeadIngestion::where('gmail_message_id', $messageId)->exists()) {
            return $this->result('already_processed', false, null, null);
        }

        $decision = $this->qualifier->qualify($email);

        if (! $decision->qualifies) {
            $status = match ($decision->rejectReason) {
                'blocklist'      => GmailLeadIngestion::STATUS_SKIPPED_BLOCKLIST,
                'no_guest_email' => GmailLeadIngestion::STATUS_SKIPPED_NO_GUEST_EMAIL,
                default          => GmailLeadIngestion::STATUS_SKIPPED_NOT_A_LEAD,
            };
            $row = $this->ledger($messageId, $email, null, $status, null, null);

            return $this->result($status, false, null, $row->id);
        }

        $guest = $decision->guest;
        $dups = BookingInquiry::findInFlightDuplicates(
            ($guest['phone'] ?? '') !== '' ? $guest['phone'] : null,
            $guest['email'],
        );
        if ($dups->isNotEmpty()) {
            $existing = $dups->first();
            $row = $this->ledger($messageId, $email, $decision->kind,
                GmailLeadIngestion::STATUS_SKIPPED_DUPLICATE_INQUIRY, $existing->id, $guest['email']);

            return $this->result(GmailLeadIngestion::STATUS_SKIPPED_DUPLICATE_INQUIRY, false, $existing->id, $row->id);
        }

        return DB::transaction(function () use ($email, $guest, $decision, $messageId): array {
            $inquiry = BookingInquiry::create([
                'reference'          => BookingInquiry::generateReference(),
                'source'             => BookingInquiry::SOURCE_EMAIL_GMAIL,
                'status'             => BookingInquiry::STATUS_NEW,
                // Cap untrusted body/subject fields to their column lengths
                // (customer_name/email 191, phone 64, tour_name_snapshot 255) —
                // otherwise an over-long field throws QueryException and the
                // message is retried forever.
                'customer_name'      => mb_substr($guest['name'] !== '' ? $guest['name'] : 'Email guest', 0, 191),
                'customer_email'     => mb_substr($guest['email'], 0, 191),
                'customer_phone'     => mb_substr($guest['phone'] ?? '', 0, 64),
                'tour_name_snapshot' => mb_substr($guest['tour_name'] !== '' ? $guest['tour_name'] : 'Email inquiry', 0, 255),
                'people_adults'      => 1,
                'people_children'    => 0,
                'submitted_at'       => now(),
                'message'            => $this->auditPreamble($decision->kind, $messageId) . ($guest['message'] ?? ''),
            ]);

            $row = $this->ledger($messageId, $email, $decision->kind,
                GmailLeadIngestion::STATUS_CREATED, $inquiry->id, $guest['email']);

            return $this->result(GmailLeadIngestion::STATUS_CREATED, true, $inquiry->id, $row->id);
        });
    }

    private function ledger(string $messageId, GmailInboundEmail $email, ?string $kind, string $status, ?int $inquiryId, ?string $guestEmail): GmailLeadIngestion
    {
        return GmailLeadIngestion::create([
            'provider'           => 'gmail',
            'gmail_message_id'   => $messageId,
            'envelope_id'        => $email->envelopeId,
            'kind'               => $kind,
            'status'             => $status,
            'booking_inquiry_id' => $inquiryId,
            'sender_email'       => mb_substr($email->senderEmail, 0, 255),
            'guest_email'        => $guestEmail !== null && $guestEmail !== '' ? mb_substr($guestEmail, 0, 255) : null,
            'subject'            => mb_substr($email->subject, 0, 500),
            'has_attachments'    => $email->hasAttachments,
            'processed_at'       => now(),
        ]);
    }

    private function auditPreamble(?string $kind, string $messageId): string
    {
        return sprintf(
            "[Imported from Gmail lead pipeline | kind=%s | msg=%s]\n\n",
            $kind ?? 'unknown',
            $messageId,
        );
    }

    /** @return array{decision: string, move: bool, inquiry_id: ?int, ingestion_id: ?int} */
    private function result(string $decision, bool $move, ?int $inquiryId, ?int $ingestionId): array
    {
        return ['decision' => $decision, 'move' => $move, 'inquiry_id' => $inquiryId, 'ingestion_id' => $ingestionId];
    }
}
