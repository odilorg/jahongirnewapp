<?php

declare(strict_types=1);

namespace App\Services\Gmail;

/**
 * The qualifier's verdict on one inbound email. `guest` is the extracted lead
 * data (name/email/phone/tour_name/message) when it qualifies, else empty.
 */
final readonly class GmailLeadDecision
{
    /** @param array<string, string> $guest */
    public function __construct(
        public bool $qualifies,
        public ?string $kind,          // 'contact_form' | 'free_form' | null
        public ?string $rejectReason,  // 'blocklist'|'not_a_lead'|'no_guest_email'|null
        public array $guest = [],
    ) {
    }

    public static function reject(string $reason): self
    {
        return new self(false, null, $reason, []);
    }

    /** @param array<string, string> $guest */
    public static function lead(string $kind, array $guest): self
    {
        return new self(true, $kind, null, $guest);
    }
}
