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
use App\Models\LeadWhatsAppIngestion;
use App\Services\Wacli\InboundWhatsAppMessage;
use Illuminate\Support\Facades\DB;

/**
 * Single-message WhatsApp → lead ingestion. Decision tree mirrors
 * IngestEmailAsLead with WA-specific skip reasons:
 *
 *   duplicate        → explicit skip, no writes
 *   from_me          → skipped_self (operator outbound)
 *   group chat       → skipped_group
 *   LID-only chat    → skipped_no_phone (privacy JID, no phone recoverable)
 *   blocklisted      → skipped_blocklist
 *   ambiguous        → skipped, leave for human
 *   no match         → create lead + interaction + +1h followup
 *   match + no open  → interaction + new +1h followup
 *   match + open     → interaction only
 *
 * All persistence wrapped in a DB transaction. Dry-run returns the decision
 * without writing anything.
 */
class IngestWhatsAppAsLead
{
    public const DECISION_CREATED_NEW_LEAD   = 'created_new_lead';
    public const DECISION_APPENDED_NO_FOLLOW = 'appended_with_new_followup';
    public const DECISION_APPENDED_EXISTING  = 'appended_to_existing_open';
    public const DECISION_SKIPPED_DUPLICATE  = 'skipped_duplicate';
    public const DECISION_SKIPPED_SELF       = 'skipped_self';
    public const DECISION_SKIPPED_GROUP      = 'skipped_group';
    public const DECISION_SKIPPED_NO_PHONE   = 'skipped_no_phone';
    public const DECISION_SKIPPED_BLOCKLIST  = 'skipped_blocklist';
    public const DECISION_AMBIGUOUS          = 'ambiguous';
    public const DECISION_FAILED             = 'failed';

    /**
     * @return array{decision: string, lead_id: ?int, ingestion_id: ?int, message: ?string}
     */
    public function handle(InboundWhatsAppMessage $msg, bool $dryRun = false): array
    {
        // 1. Hard dedupe on composite remote_message_id
        $existing = LeadWhatsAppIngestion::where('provider', LeadWhatsAppIngestion::PROVIDER_WACLI)
            ->where('remote_message_id', $msg->remoteMessageId)
            ->first();
        if ($existing !== null) {
            return $this->decision(self::DECISION_SKIPPED_DUPLICATE, $existing->lead_id, $existing->id);
        }

        // 2. Operator outbound — never a lead
        if ($msg->isFromMe) {
            return $this->persistSkip($msg, LeadWhatsAppIngestion::STATUS_SKIPPED_SELF, null, $dryRun)
                + ['decision' => self::DECISION_SKIPPED_SELF];
        }

        // 3. Groups — explicit policy decision, never ingest
        if ($msg->isGroup() && config('wacli.skip_group_chats', true)) {
            return $this->persistSkip($msg, LeadWhatsAppIngestion::STATUS_SKIPPED_GROUP, null, $dryRun)
                + ['decision' => self::DECISION_SKIPPED_GROUP];
        }

        // 4. LID-only — no phone to match against. Park for human.
        if ($msg->isLidOnly() && config('wacli.skip_lid_only', true)) {
            return $this->persistSkip($msg, LeadWhatsAppIngestion::STATUS_SKIPPED_NO_PHONE, null, $dryRun)
                + ['decision' => self::DECISION_SKIPPED_NO_PHONE];
        }

        // 5. Extract phone from phone-based JID
        $phone = $msg->extractPhone();
        if ($phone === null) {
            return $this->persistSkip($msg, LeadWhatsAppIngestion::STATUS_SKIPPED_NO_PHONE, null, $dryRun)
                + ['decision' => self::DECISION_SKIPPED_NO_PHONE];
        }

        // 6. Self numbers — defence-in-depth alongside FromMe
        $digits = ltrim($phone, '+');
        $selfNumbers = config('wacli.self_numbers', []);
        if (is_array($selfNumbers) && in_array($digits, $selfNumbers, true)) {
            return $this->persistSkip($msg, LeadWhatsAppIngestion::STATUS_SKIPPED_SELF, $phone, $dryRun)
                + ['decision' => self::DECISION_SKIPPED_SELF];
        }

        // 7. Blocklist
        if ($this->isBlocklisted($phone, $msg->chatJid, $msg->chatName)) {
            return $this->persistSkip($msg, LeadWhatsAppIngestion::STATUS_SKIPPED_BLOCKLIST, $phone, $dryRun)
                + ['decision' => self::DECISION_SKIPPED_BLOCKLIST];
        }

        // 8. Resolve or create the lead
        try {
            $lead = $dryRun
                ? $this->probeMatch($phone)
                : app(FindOrCreateLeadByContact::class)->handle(
                    ['whatsapp_number' => $phone],
                    ['name' => $msg->chatName, 'source' => LeadSource::WhatsAppIn->value],
                );
        } catch (AmbiguousLeadMatchException $e) {
            return $this->persistAmbiguous($msg, $phone, $e->getMessage(), $dryRun)
                + ['decision' => self::DECISION_AMBIGUOUS];
        }

        // Dry-run short-circuits here: no writes, no IMAP flag changes.
        if ($dryRun) {
            $decision = $lead === null
                ? self::DECISION_CREATED_NEW_LEAD
                : ($lead->followUps()->where('status', LeadFollowUpStatus::Open->value)->exists()
                    ? self::DECISION_APPENDED_EXISTING
                    : self::DECISION_APPENDED_NO_FOLLOW);

            return $this->decision($decision, $lead?->id, null);
        }

        return DB::transaction(function () use ($msg, $lead, $phone) {
            $wasNew = $lead->wasRecentlyCreated === true;

            app(LogInteraction::class)->handle($lead, [
                'channel'   => LeadInteractionChannel::WhatsApp->value,
                'direction' => LeadInteractionDirection::Inbound->value,
                'subject'   => null,
                'body'      => $msg->body !== '' ? $msg->body : '(empty message)',
                'is_important' => false,
            ]);

            $hasOpen = $lead->followUps()->where('status', LeadFollowUpStatus::Open->value)->exists();
            if ($wasNew || ! $hasOpen) {
                app(CreateFollowUp::class)->handle($lead, [
                    'due_at' => now()->addHour(),
                    'type'   => LeadFollowUpType::Message->value,
                    'note'   => 'Inbound WhatsApp from '.($msg->chatName ?: $phone),
                ]);
            }

            $ingestion = LeadWhatsAppIngestion::create([
                'provider'          => LeadWhatsAppIngestion::PROVIDER_WACLI,
                'remote_message_id' => $msg->remoteMessageId,
                'chat_jid'          => $msg->chatJid,
                'sender_jid'        => $msg->senderJid,
                'chat_name'         => $msg->chatName,
                'lead_id'           => $lead->id,
                'status'            => LeadWhatsAppIngestion::STATUS_PROCESSED,
                'sender_phone'      => $phone,
                'body_preview'      => $this->bodyPreview($msg->body),
                'from_me'           => $msg->isFromMe,
                'has_media'         => $msg->hasMedia(),
                'media_type'        => $msg->mediaType,
                'remote_sent_at'    => $msg->sentAt,
                'processed_at'      => now(),
            ]);

            $decision = $wasNew
                ? self::DECISION_CREATED_NEW_LEAD
                : ($hasOpen ? self::DECISION_APPENDED_EXISTING : self::DECISION_APPENDED_NO_FOLLOW);

            return $this->decision($decision, $lead->id, $ingestion->id);
        });
    }

    private function isBlocklisted(?string $phone, string $chatJid, ?string $chatName): bool
    {
        $list = config('wacli.sender_blocklist', []);
        if (! is_array($list) || $list === []) {
            return false;
        }

        $haystacks = array_filter([
            $phone !== null ? strtolower($phone) : null,
            strtolower($chatJid),
            $chatName !== null ? strtolower($chatName) : null,
        ]);

        foreach ($list as $needle) {
            if ($needle === '' || ! is_string($needle)) {
                continue;
            }
            $lowNeedle = strtolower($needle);
            foreach ($haystacks as $h) {
                if (str_contains($h, $lowNeedle)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function probeMatch(string $phone): ?Lead
    {
        $matches = Lead::where('whatsapp_number', $phone)->get();
        if ($matches->count() > 1) {
            throw new AmbiguousLeadMatchException(
                "Multiple leads share whatsapp_number={$phone}",
                $matches,
            );
        }

        return $matches->first();
    }

    /** @return array{lead_id: ?int, ingestion_id: ?int, message: ?string} */
    private function persistSkip(InboundWhatsAppMessage $msg, string $status, ?string $phone, bool $dryRun): array
    {
        if ($dryRun) {
            return ['lead_id' => null, 'ingestion_id' => null, 'message' => null];
        }

        $row = LeadWhatsAppIngestion::create([
            'provider'          => LeadWhatsAppIngestion::PROVIDER_WACLI,
            'remote_message_id' => $msg->remoteMessageId,
            'chat_jid'          => $msg->chatJid,
            'sender_jid'        => $msg->senderJid,
            'chat_name'         => $msg->chatName,
            'lead_id'           => null,
            'status'            => $status,
            'sender_phone'      => $phone,
            'body_preview'      => $this->bodyPreview($msg->body),
            'from_me'           => $msg->isFromMe,
            'has_media'         => $msg->hasMedia(),
            'media_type'        => $msg->mediaType,
            'remote_sent_at'    => $msg->sentAt,
            'processed_at'      => now(),
        ]);

        return ['lead_id' => null, 'ingestion_id' => $row->id, 'message' => null];
    }

    /** @return array{lead_id: ?int, ingestion_id: ?int, message: ?string} */
    private function persistAmbiguous(InboundWhatsAppMessage $msg, string $phone, string $reason, bool $dryRun): array
    {
        if ($dryRun) {
            return ['lead_id' => null, 'ingestion_id' => null, 'message' => $reason];
        }

        $row = LeadWhatsAppIngestion::create([
            'provider'          => LeadWhatsAppIngestion::PROVIDER_WACLI,
            'remote_message_id' => $msg->remoteMessageId,
            'chat_jid'          => $msg->chatJid,
            'sender_jid'        => $msg->senderJid,
            'chat_name'         => $msg->chatName,
            'lead_id'           => null,
            'status'            => LeadWhatsAppIngestion::STATUS_AMBIGUOUS,
            'sender_phone'      => $phone,
            'body_preview'      => $this->bodyPreview($msg->body),
            'from_me'           => $msg->isFromMe,
            'has_media'         => $msg->hasMedia(),
            'media_type'        => $msg->mediaType,
            'remote_sent_at'    => $msg->sentAt,
            'error_message'     => $reason,
            'processed_at'      => now(),
        ]);

        return ['lead_id' => null, 'ingestion_id' => $row->id, 'message' => $reason];
    }

    private function bodyPreview(string $body): ?string
    {
        $trimmed = trim($body);
        if ($trimmed === '') {
            return null;
        }

        return mb_substr($trimmed, 0, 500);
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
