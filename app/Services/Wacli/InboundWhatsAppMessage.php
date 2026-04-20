<?php

declare(strict_types=1);

namespace App\Services\Wacli;

use Carbon\CarbonInterface;

/**
 * Normalized inbound-WhatsApp payload. Decouples IngestWhatsAppAsLead from
 * wacli's JSON shape so the decision tree is testable without SSH.
 *
 * remoteMessageId is composite ({chatJid}:{msgId}) because WhatsApp protocol
 * does not guarantee MsgID uniqueness across chats.
 */
final readonly class InboundWhatsAppMessage
{
    public function __construct(
        public string $remoteMessageId,
        public string $msgId,
        public string $chatJid,
        public ?string $senderJid,
        public ?string $chatName,
        public string $body,
        public bool $isFromMe,
        public ?string $mediaType,
        public ?CarbonInterface $sentAt,
    ) {
    }

    public function hasMedia(): bool
    {
        return $this->mediaType !== null && $this->mediaType !== '';
    }

    public function isGroup(): bool
    {
        return str_ends_with($this->chatJid, '@g.us');
    }

    public function isLidOnly(): bool
    {
        return str_ends_with($this->chatJid, '@lid');
    }

    public function isPhoneBased(): bool
    {
        return str_ends_with($this->chatJid, '@s.whatsapp.net');
    }

    /**
     * Extract the phone number (digits only, +-prefixed) from a phone-based
     * JID. Returns null for @lid / @g.us / malformed JIDs.
     */
    public function extractPhone(): ?string
    {
        if (! $this->isPhoneBased()) {
            return null;
        }
        $prefix = explode('@', $this->chatJid, 2)[0] ?? '';
        $digits = preg_replace('/\D/', '', $prefix);

        return $digits !== '' && $digits !== null ? '+'.$digits : null;
    }
}
