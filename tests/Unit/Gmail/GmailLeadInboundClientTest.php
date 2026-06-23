<?php

declare(strict_types=1);

namespace Tests\Unit\Gmail;

use App\Services\Gmail\GmailLeadInboundClient;
use PHPUnit\Framework\TestCase;

/**
 * Covers toInboundEmail()'s header/body split + Message-ID extraction — the path
 * that the missing `--header Message-ID` blocker lived in. Pure (no himalaya):
 * we hand toInboundEmail a realistic `message read --preview --header Message-ID`
 * sample ("Message-ID: <…>\n\n<body>").
 */
class GmailLeadInboundClientTest extends TestCase
{
    private function env(): array
    {
        return ['id' => '42', 'from_addr' => 'info@jahongir-travel.uz', 'from_name' => 'Jahongir Travel', 'subject' => 'New inquiry', 'has_attachment' => false];
    }

    public function test_extracts_message_id_and_strips_it_from_body(): void
    {
        $raw = "Message-ID: <CABc123@mail.gmail.com>\n\nName : Jane\nEmail : jane@x.com\nMessage : hi";
        $email = (new GmailLeadInboundClient())->toInboundEmail($this->env(), $raw);

        $this->assertSame('CABc123@mail.gmail.com', $email->messageId);   // brackets stripped
        $this->assertStringContainsString('Name : Jane', $email->body);
        $this->assertStringNotContainsString('Message-ID', $email->body); // header not in body
    }

    public function test_message_id_bracket_optional(): void
    {
        $raw = "Message-ID: noangle@host\n\nbody here";
        $this->assertSame('noangle@host', (new GmailLeadInboundClient())->toInboundEmail($this->env(), $raw)->messageId);
    }

    public function test_null_message_id_when_absent(): void
    {
        $email = (new GmailLeadInboundClient())->toInboundEmail($this->env(), "Just a body with no headers");
        $this->assertNull($email->messageId);
    }
}
