<?php

declare(strict_types=1);

namespace App\Services\Gmail;

/**
 * Read-only normalized view of one inbound Gmail message (envelope meta +
 * extracted body). Built by GmailLeadInboundClient; consumed by the qualifier.
 * Mirrors App\Services\Zoho\InboundEmail.
 */
final readonly class GmailInboundEmail
{
    public function __construct(
        public string $envelopeId,
        public ?string $messageId,
        public string $senderEmail,
        public string $senderName,
        public string $subject,
        public string $body,
        public bool $hasAttachments,
    ) {
    }
}
