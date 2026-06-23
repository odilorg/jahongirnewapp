<?php

declare(strict_types=1);

namespace App\Services\Gmail;

/**
 * Decides whether one inbound Gmail message is a genuine tour lead, and extracts
 * the guest fields. PURE (no IO, no DB) so it is fully unit-testable on real
 * sample bodies. Policy is allow-list / default-ignore: an email qualifies ONLY
 * if it matches the website contact-form template or is a plausible direct guest
 * email; everything else is rejected.
 *
 * Crucial: for a CONTACT-FORM notification the guest email is the body `Email :`
 * field, NOT the envelope sender (which is the website mailer). The sender
 * blocklist therefore applies only to FREE-FORM (direct) emails — a contact-form
 * notifier may legitimately be a `noreply@` address.
 */
class GmailLeadQualifier
{
    /**
     * @param array<int, string> $notifierSenders optional contact-form sender allow-list
     * @param array<int, string> $blocklist       direct-email sender blocklist
     * @param bool $freeFormEnabled               ingest direct (non-template) emails too
     */
    public function __construct(
        private array $notifierSenders = [],
        private array $blocklist = [],
        private bool $freeFormEnabled = false,
    ) {
    }

    public function qualify(GmailInboundEmail $email): GmailLeadDecision
    {
        $from = strtolower(trim($email->senderEmail));

        // 1) Website contact-form notification (the high-value path).
        $contact = $this->parseContactForm($email->body);
        if ($contact !== null) {
            // EXACT notifier-sender match required (not substring) — a contact
            // form must come from the configured website notifier, nothing else.
            if ($this->notifierSenders !== [] && ! $this->senderIsNotifier($from)) {
                return GmailLeadDecision::reject('not_a_lead');
            }
            if ($contact['email'] === '') {
                return GmailLeadDecision::reject('no_guest_email');
            }
            return GmailLeadDecision::lead('contact_form', $contact);
        }

        // 2) Direct (free-form) email: sender IS the guest -> apply the blocklist.
        if ($this->senderMatches($from, $this->blocklist)) {
            return GmailLeadDecision::reject('blocklist');
        }
        // OFF by default: free-form has no template guard, so the label filter is
        // its only boundary. Opt in via config once the label is trusted.
        if ($this->freeFormEnabled && filter_var($from, FILTER_VALIDATE_EMAIL) && trim($email->body) !== '') {
            return GmailLeadDecision::lead('free_form', [
                'name'      => $email->senderName !== '' ? $email->senderName : explode('@', $from)[0],
                'email'     => $from,
                'phone'     => '',
                'tour_name' => $email->subject !== '' ? $email->subject : 'Email inquiry',
                'message'   => trim($email->body),
            ]);
        }

        return GmailLeadDecision::reject('not_a_lead');
    }

    /** EXACT match against the configured notifier sender(s). */
    private function senderIsNotifier(string $from): bool
    {
        foreach ($this->notifierSenders as $n) {
            if ($n !== '' && strtolower(trim($n)) === $from) {
                return true;
            }
        }
        return false;
    }

    /** Substring match — used for the (free-form) sender blocklist only. */
    private function senderMatches(string $from, array $needles): bool
    {
        foreach ($needles as $n) {
            if ($n !== '' && str_contains($from, strtolower($n))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Parse the `Name : / Email : / Subject : / Message :` template. Returns null
     * when the template isn't present (so the caller falls through to free-form).
     *
     * @return array<string, string>|null
     */
    private function parseContactForm(string $body): ?array
    {
        // Require at least the Name and Message labels to treat it as the form.
        if (! preg_match('/^\s*Name\s*:/mi', $body) || ! preg_match('/^\s*Message\s*:/mi', $body)) {
            return null;
        }

        $emailRaw = $this->field($body, 'Email');
        $email = '';
        if ($emailRaw !== '' && preg_match('/[\w.+-]+@[\w-]+\.[\w.-]+/', $emailRaw, $m)) {
            $email = strtolower($m[0]);
        }
        $subject = $this->field($body, 'Subject');

        return [
            'name'      => $this->field($body, 'Name'),
            'email'     => $email,
            'phone'     => $this->field($body, 'Phone'),
            'tour_name' => $subject !== '' ? $subject : 'Email inquiry',
            'message'   => $this->messageField($body),
        ];
    }

    private function field(string $body, string $label): string
    {
        if (preg_match('/^\s*' . preg_quote($label, '/') . '\s*:\s*(.+?)\s*$/mi', $body, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    /** Everything after "Message :" to the end of the body (the free text). */
    private function messageField(string $body): string
    {
        if (preg_match('/^\s*Message\s*:\s*(.+)$/mis', $body, $m)) {
            return trim($m[1]);
        }
        return '';
    }
}
