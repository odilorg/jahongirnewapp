<?php

declare(strict_types=1);

namespace Tests\Unit\Gmail;

use App\Services\Gmail\GmailInboundEmail;
use App\Services\Gmail\GmailLeadQualifier;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests for the Gmail lead qualifier (no DB, no mailbox). Real-sample
 * driven: the Robert Clayton contact-form email is the red-before-green fixture
 * for the gap that prompted this feature.
 */
class GmailLeadQualifierTest extends TestCase
{
    private function email(string $body, string $from = 'noreply@jahongir-travel.uz', string $name = 'Jahongir Travel', string $subject = 'New inquiry'): GmailInboundEmail
    {
        return new GmailInboundEmail(
            envelopeId: '1',
            messageId: '<abc@x>',
            senderEmail: $from,
            senderName: $name,
            subject: $subject,
            body: $body,
            hasAttachments: false,
        );
    }

    // The real incident email (sanitized structure, same template + guest address).
    private const ROBERT = "Name : Robert Clayton\nEmail : rtb10088@gmail.com\nSubject : Potential Tour\n"
        . "Message : Hi - Details of potential trip Arrival Urgench - Friday 2/10/2026 19.00 "
        . "1) Required transport to Khiva 2) Day tour of Khiva ... 7) Day trip to Shahrisabz. "
        . "Please advise approx potential cost. Many Thanks Robert Clayton did it catch this one ?";

    public function test_contact_form_qualifies_and_extracts_guest_from_body(): void
    {
        // Sender is the website notifier (a noreply@ address) — must NOT be blocked.
        $d = (new GmailLeadQualifier([], ['noreply@', 'mailer-daemon@']))->qualify($this->email(self::ROBERT));

        $this->assertTrue($d->qualifies);
        $this->assertSame('contact_form', $d->kind);
        $this->assertSame('rtb10088@gmail.com', $d->guest['email']); // from BODY, not the notifier From
        $this->assertSame('Robert Clayton', $d->guest['name']);
        $this->assertSame('Potential Tour', $d->guest['tour_name']);
        $this->assertStringContainsString('Urgench', $d->guest['message']);
        $this->assertStringContainsString('Shahrisabz', $d->guest['message']);
    }

    public function test_contact_form_missing_email_is_rejected(): void
    {
        $body = "Name : John Doe\nSubject : Tour\nMessage : I want a tour please";
        $d = (new GmailLeadQualifier())->qualify($this->email($body));
        $this->assertFalse($d->qualifies);
        $this->assertSame('no_guest_email', $d->rejectReason);
    }

    public function test_direct_freeform_qualifies_only_when_enabled(): void
    {
        $email = $this->email('Hello, do you run private Samarkand day tours in October?', 'jane@guest.com', 'Jane R', 'Samarkand tour');

        // OFF by default -> rejected.
        $this->assertFalse((new GmailLeadQualifier([], ['mailer-daemon@']))->qualify($email)->qualifies);

        // Opt-in -> qualifies as free_form.
        $d = (new GmailLeadQualifier([], ['mailer-daemon@'], true))->qualify($email);
        $this->assertTrue($d->qualifies);
        $this->assertSame('free_form', $d->kind);
        $this->assertSame('jane@guest.com', $d->guest['email']);
        $this->assertSame('Jane R', $d->guest['name']);
        $this->assertSame('Samarkand tour', $d->guest['tour_name']);
    }

    public function test_blocklisted_direct_sender_is_rejected(): void
    {
        $d = (new GmailLeadQualifier([], ['mailer-daemon@', 'noreply@']))->qualify(
            $this->email('Delivery failed', 'mailer-daemon@googlemail.com', '', 'Undelivered')
        );
        $this->assertFalse($d->qualifies);
        $this->assertSame('blocklist', $d->rejectReason);
    }

    public function test_non_lead_without_template_or_valid_sender_is_rejected(): void
    {
        $d = (new GmailLeadQualifier())->qualify($this->email('', 'not-an-email', '', ''));
        $this->assertFalse($d->qualifies);
        $this->assertSame('not_a_lead', $d->rejectReason);
    }

    public function test_notifier_restriction_rejects_contact_form_from_unknown_sender(): void
    {
        // notifier allow-list configured; contact-form arrives from a different sender.
        $q = new GmailLeadQualifier(['mailer@jahongir-travel.uz'], []);
        $this->assertFalse($q->qualify($this->email(self::ROBERT, 'random@elsewhere.com'))->qualifies);
        // ...but from the configured notifier it qualifies.
        $this->assertTrue($q->qualify($this->email(self::ROBERT, 'mailer@jahongir-travel.uz'))->qualifies);
    }
}
