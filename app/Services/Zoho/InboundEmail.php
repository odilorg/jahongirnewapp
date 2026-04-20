<?php

declare(strict_types=1);

namespace App\Services\Zoho;

/**
 * Normalized inbound-email payload. Decouples IngestEmailAsLead from the
 * IMAP client library so the decision tree is testable without a mailbox.
 */
final readonly class InboundEmail
{
    /**
     * @param  array<int, string>  $attachmentFilenames
     */
    public function __construct(
        public string $messageId,
        public ?string $uid,
        public string $folder,
        public ?string $senderEmail,
        public ?string $senderName,
        public string $subject,
        public string $body,
        public bool $hasAttachments,
        public array $attachmentFilenames = [],
    ) {
    }
}
