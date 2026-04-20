<?php

declare(strict_types=1);

namespace App\Actions\Leads;

use App\Enums\LeadFollowUpStatus;
use App\Enums\LeadFollowUpType;
use App\Enums\LeadInteractionChannel;
use App\Enums\LeadInteractionDirection;
use App\Enums\LeadSource;
use App\Exceptions\Leads\AmbiguousLeadMatchException;
use App\Models\Lead;
use App\Models\LeadEmailIngestion;
use App\Services\Zoho\InboundEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Single-message ingestion orchestrator for inbound Zoho Mail.
 *
 * Implements the decision tree approved in Phase 3a:
 *   - duplicate remote_message_id        → explicit skip (idempotent re-run)
 *   - empty / malformed sender            → skip, leave in INBOX for human
 *   - blocklisted sender                  → skip, mark processed
 *   - ambiguous sender match              → skip, leave in INBOX for human
 *   - no match                            → create lead + interaction + followup
 *   - match + no open follow-up           → interaction + new followup
 *   - match + open follow-up              → interaction only
 *
 * Side effects are wrapped in a DB transaction so the mailbox is never
 * mutated before the local state is safely persisted. Dry-run mode returns
 * the decision without any writes.
 */
class IngestEmailAsLead
{
    public const DECISION_CREATED_NEW_LEAD   = 'created_new_lead';
    public const DECISION_APPENDED_NO_FOLLOW = 'appended_with_new_followup';
    public const DECISION_APPENDED_EXISTING  = 'appended_to_existing_open';
    public const DECISION_SKIPPED_DUPLICATE  = 'skipped_duplicate';
    public const DECISION_SKIPPED_NO_SENDER  = 'skipped_no_sender';
    public const DECISION_SKIPPED_BLOCKLIST  = 'skipped_blocklist';
    public const DECISION_AMBIGUOUS          = 'ambiguous';
    public const DECISION_FAILED             = 'failed';

    /**
     * @return array{decision: string, lead_id: ?int, ingestion_id: ?int, message: ?string}
     */
    public function handle(InboundEmail $email, bool $dryRun = false): array
    {
        // 1. Hard dedupe by remote_message_id.
        $existing = LeadEmailIngestion::where('provider', LeadEmailIngestion::PROVIDER_ZOHO_MAIL)
            ->where('remote_message_id', $email->messageId)
            ->first();

        if ($existing !== null) {
            return $this->decision(self::DECISION_SKIPPED_DUPLICATE, $existing->lead_id, $existing->id);
        }

        // 2. No usable sender → skip, don't mark processed (human reviews).
        if (empty($email->senderEmail)) {
            return $this->persistSkip($email, LeadEmailIngestion::STATUS_SKIPPED_NO_SENDER, $dryRun)
                + ['decision' => self::DECISION_SKIPPED_NO_SENDER];
        }

        $sender = strtolower(trim($email->senderEmail));

        // 3. Blocklisted sender → skip AND mark processed (noise, don't re-read).
        if ($this->isBlocklisted($sender)) {
            return $this->persistSkip($email, LeadEmailIngestion::STATUS_SKIPPED_BLOCKLIST, $dryRun)
                + ['decision' => self::DECISION_SKIPPED_BLOCKLIST];
        }

        // 4. Resolve or create the lead.
        try {
            $lead = $dryRun
                ? $this->probeMatch($sender)
                : app(FindOrCreateLeadByContact::class)->handle(
                    ['email' => $sender],
                    ['name' => $email->senderName, 'source' => LeadSource::EmailIn->value],
                );
        } catch (AmbiguousLeadMatchException $e) {
            return $this->persistAmbiguous($email, $e->getMessage(), $dryRun)
                + ['decision' => self::DECISION_AMBIGUOUS];
        }

        // Dry-run ends here — no DB writes, no IMAP mutation.
        if ($dryRun) {
            $decision = $lead === null
                ? self::DECISION_CREATED_NEW_LEAD
                : ($lead->followUps()->where('status', LeadFollowUpStatus::Open->value)->exists()
                    ? self::DECISION_APPENDED_EXISTING
                    : self::DECISION_APPENDED_NO_FOLLOW);

            return $this->decision($decision, $lead?->id, null);
        }

        // 5. Transactional write: interaction + optional follow-up + ingestion row.
        return DB::transaction(function () use ($email, $lead) {
            $wasNew = $lead->wasRecentlyCreated === true;

            app(LogInteraction::class)->handle($lead, [
                'channel'   => LeadInteractionChannel::Email->value,
                'direction' => LeadInteractionDirection::Inbound->value,
                'subject'   => $email->subject ?: null,
                'body'      => $email->body ?: '(empty body)',
            ]);

            // New leads get the +1h message follow-up. Existing leads with no
            // open follow-up get the same. Leads with an open follow-up do not.
            $hasOpen = $lead->followUps()->where('status', LeadFollowUpStatus::Open->value)->exists();

            if ($wasNew || ! $hasOpen) {
                app(CreateFollowUp::class)->handle($lead, [
                    'due_at' => now()->addHour(),
                    'type'   => LeadFollowUpType::Message->value,
                    'note'   => 'Inbound email: '.($email->subject ?: '(no subject)'),
                ]);
            }

            $ingestion = LeadEmailIngestion::create([
                'provider'             => LeadEmailIngestion::PROVIDER_ZOHO_MAIL,
                'remote_message_id'    => $email->messageId,
                'remote_uid'           => $email->uid,
                'remote_folder'        => $email->folder,
                'lead_id'              => $lead->id,
                'status'               => LeadEmailIngestion::STATUS_PROCESSED,
                'sender_email'         => $email->senderEmail,
                'subject'              => $email->subject ?: null,
                'has_attachments'      => $email->hasAttachments,
                'attachment_filenames' => $email->attachmentFilenames ?: null,
                'processed_at'         => now(),
            ]);

            $decision = $wasNew
                ? self::DECISION_CREATED_NEW_LEAD
                : ($hasOpen ? self::DECISION_APPENDED_EXISTING : self::DECISION_APPENDED_NO_FOLLOW);

            return $this->decision($decision, $lead->id, $ingestion->id);
        });
    }

    private function isBlocklisted(string $sender): bool
    {
        $list = config('zoho.mail_inbound.sender_blocklist', []);
        foreach ($list as $needle) {
            if ($needle !== '' && str_contains($sender, strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    private function probeMatch(string $sender): ?Lead
    {
        $matches = Lead::where('email', $sender)->get();
        if ($matches->count() > 1) {
            throw new AmbiguousLeadMatchException(
                "Multiple leads share email={$sender}",
                $matches,
            );
        }

        return $matches->first();
    }

    /** @return array{lead_id: ?int, ingestion_id: ?int, message: ?string} */
    private function persistSkip(InboundEmail $email, string $status, bool $dryRun): array
    {
        if ($dryRun) {
            return ['lead_id' => null, 'ingestion_id' => null, 'message' => null];
        }

        try {
            $row = LeadEmailIngestion::create([
                'provider'             => LeadEmailIngestion::PROVIDER_ZOHO_MAIL,
                'remote_message_id'    => $email->messageId,
                'remote_uid'           => $email->uid,
                'remote_folder'        => $email->folder,
                'lead_id'              => null,
                'status'               => $status,
                'sender_email'         => $email->senderEmail,
                'subject'              => $email->subject ?: null,
                'has_attachments'      => $email->hasAttachments,
                'attachment_filenames' => $email->attachmentFilenames ?: null,
                'processed_at'         => now(),
            ]);

            return ['lead_id' => null, 'ingestion_id' => $row->id, 'message' => null];
        } catch (Throwable $e) {
            Log::warning('Failed to persist ingestion skip row', ['message_id' => $email->messageId, 'error' => $e->getMessage()]);

            return ['lead_id' => null, 'ingestion_id' => null, 'message' => $e->getMessage()];
        }
    }

    /** @return array{lead_id: ?int, ingestion_id: ?int, message: ?string} */
    private function persistAmbiguous(InboundEmail $email, string $reason, bool $dryRun): array
    {
        if ($dryRun) {
            return ['lead_id' => null, 'ingestion_id' => null, 'message' => $reason];
        }

        $row = LeadEmailIngestion::create([
            'provider'             => LeadEmailIngestion::PROVIDER_ZOHO_MAIL,
            'remote_message_id'    => $email->messageId,
            'remote_uid'           => $email->uid,
            'remote_folder'        => $email->folder,
            'lead_id'              => null,
            'status'               => LeadEmailIngestion::STATUS_AMBIGUOUS,
            'sender_email'         => $email->senderEmail,
            'subject'              => $email->subject ?: null,
            'has_attachments'      => $email->hasAttachments,
            'attachment_filenames' => $email->attachmentFilenames ?: null,
            'error_message'        => $reason,
            'processed_at'         => now(),
        ]);

        return ['lead_id' => null, 'ingestion_id' => $row->id, 'message' => $reason];
    }

    /** @return array{decision: string, lead_id: ?int, ingestion_id: ?int, message: ?string} */
    private function decision(string $decision, ?int $leadId, ?int $ingestionId, ?string $message = null): array
    {
        return [
            'decision'     => $decision,
            'lead_id'      => $leadId,
            'ingestion_id' => $ingestionId,
            'message'      => $message,
        ];
    }
}
