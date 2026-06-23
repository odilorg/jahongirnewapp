<?php

declare(strict_types=1);

namespace Tests\Feature\Gmail;

use App\Actions\BookingInquiries\IngestGmailEmailAsInquiry;
use App\Models\BookingInquiry;
use App\Models\GmailLeadIngestion;
use App\Services\Gmail\GmailInboundEmail;
use App\Services\Gmail\GmailLeadQualifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IngestGmailEmailAsInquiryTest extends TestCase
{
    use RefreshDatabase;

    private const ROBERT = "Name : Robert Clayton\nEmail : rtb10088@gmail.com\nSubject : Potential Tour\n"
        . "Message : Hi - 7-day custom trip Urgench/Khiva/Bukhara/Samarkand/Shahrisabz. Please advise cost.";

    private function action(): IngestGmailEmailAsInquiry
    {
        return new IngestGmailEmailAsInquiry(
            new GmailLeadQualifier([], ['mailer-daemon@', 'noreply@'])
        );
    }

    private function email(string $body, string $msgId = '<m1@x>', string $from = 'info@jahongir-travel.uz'): GmailInboundEmail
    {
        return new GmailInboundEmail('100', $msgId, $from, 'Jahongir Travel', 'New inquiry', $body, false);
    }

    public function test_contact_form_creates_booking_inquiry(): void
    {
        $res = $this->action()->ingest($this->email(self::ROBERT));

        $this->assertSame('created', $res['decision']);
        $this->assertTrue($res['move']);

        $inq = BookingInquiry::find($res['inquiry_id']);
        $this->assertNotNull($inq);
        $this->assertSame(BookingInquiry::SOURCE_EMAIL_GMAIL, $inq->source);
        $this->assertSame(BookingInquiry::STATUS_NEW, $inq->status);
        $this->assertSame('rtb10088@gmail.com', $inq->customer_email);  // from BODY
        $this->assertSame('Robert Clayton', $inq->customer_name);
        $this->assertSame('Potential Tour', $inq->tour_name_snapshot);
        $this->assertStringContainsString('Shahrisabz', $inq->message);
        $this->assertNull($inq->travel_date);

        $this->assertDatabaseHas('gmail_lead_ingestions', [
            'gmail_message_id'   => '<m1@x>',
            'status'             => GmailLeadIngestion::STATUS_CREATED,
            'booking_inquiry_id' => $inq->id,
        ]);
    }

    public function test_idempotent_second_run_does_not_double_create(): void
    {
        $this->action()->ingest($this->email(self::ROBERT, '<dup@x>'));
        $second = $this->action()->ingest($this->email(self::ROBERT, '<dup@x>'));

        $this->assertSame('already_processed', $second['decision']);
        $this->assertSame(1, BookingInquiry::where('source', BookingInquiry::SOURCE_EMAIL_GMAIL)->count());
        $this->assertSame(1, GmailLeadIngestion::count());
    }

    public function test_duplicate_of_existing_inflight_inquiry_is_skipped(): void
    {
        BookingInquiry::create([
            'reference' => BookingInquiry::generateReference(), 'source' => BookingInquiry::SOURCE_WEBSITE,
            'status' => BookingInquiry::STATUS_NEW, 'customer_name' => 'Robert Clayton',
            'customer_email' => 'rtb10088@gmail.com', 'customer_phone' => '',
            'tour_name_snapshot' => 'Existing', 'people_adults' => 1, 'people_children' => 0,
            'submitted_at' => now(), 'message' => 'via website',
        ]);

        $res = $this->action()->ingest($this->email(self::ROBERT, '<new@x>'));

        $this->assertSame(GmailLeadIngestion::STATUS_SKIPPED_DUPLICATE_INQUIRY, $res['decision']);
        $this->assertFalse($res['move']);
        $this->assertSame(0, BookingInquiry::where('source', BookingInquiry::SOURCE_EMAIL_GMAIL)->count());
        $this->assertDatabaseHas('gmail_lead_ingestions', ['status' => GmailLeadIngestion::STATUS_SKIPPED_DUPLICATE_INQUIRY]);
    }

    public function test_over_long_fields_are_capped_not_thrown(): void
    {
        $longSubject = str_repeat('Long tour name ', 30);  // ~450 chars > 255
        $body = "Name : " . str_repeat('X', 300) . "\nEmail : big@guest.com\nSubject : {$longSubject}\nMessage : hello";
        $res = $this->action()->ingest($this->email($body, '<big@x>'));

        $this->assertSame('created', $res['decision']);
        $inq = BookingInquiry::find($res['inquiry_id']);
        $this->assertLessThanOrEqual(255, mb_strlen($inq->tour_name_snapshot));
        $this->assertLessThanOrEqual(191, mb_strlen($inq->customer_name));
    }

    public function test_synthetic_key_is_idempotent_when_no_message_id(): void
    {
        // messageId null -> action derives sha256(subject|envelopeId); same input twice = one inquiry.
        $e = new GmailInboundEmail('100', null, 'info@jahongir-travel.uz', 'Jahongir Travel', 'New inquiry', self::ROBERT, false);
        $this->action()->ingest($e);
        $second = $this->action()->ingest($e);

        $this->assertSame('already_processed', $second['decision']);
        $this->assertSame(1, GmailLeadIngestion::count());
    }

    public function test_spam_is_not_ingested(): void
    {
        $res = $this->action()->ingest($this->email('Delivery failed', '<bounce@x>', 'mailer-daemon@googlemail.com'));

        $this->assertSame(GmailLeadIngestion::STATUS_SKIPPED_BLOCKLIST, $res['decision']);
        $this->assertSame(0, BookingInquiry::count());
        $this->assertDatabaseHas('gmail_lead_ingestions', ['status' => GmailLeadIngestion::STATUS_SKIPPED_BLOCKLIST]);
    }
}
