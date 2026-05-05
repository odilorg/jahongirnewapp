<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\GygInboundEmail;
use App\Services\OwnerAlertService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Integration coverage for the body-missing safety guard.
 *
 * Audit 2026-05-05 found 14 booking + 5 cancellation rows stored with
 * processing_status='skipped' and body_text=NULL because the Gmail body
 * read timed out. None were lost (all were duplicate redeliveries), but
 * a first-delivery timeout would have silently dropped a real booking.
 *
 * After the fix the fetcher always stores 'fetched', and process-emails
 * marks subject-only actionable rows as 'failed' with a clear parse_error
 * AND fires an ops alert — guaranteeing operator visibility.
 */
class GygProcessEmailsEmptyBodyGuardTest extends TestCase
{
    use DatabaseTransactions;

    private MockInterface $alertMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alertMock = $this->mock(OwnerAlertService::class);
    }

    private function makeRow(string $subject, ?string $body, string $from = 'do-not-reply@notification.getyourguide.com'): GygInboundEmail
    {
        return GygInboundEmail::create([
            'email_message_id' => 'test:'.uniqid(),
            'email_from' => $from,
            'email_to' => 'supplier@example.com',
            'email_subject' => $subject,
            'email_date' => now(),
            'body_text' => $body,
            'body_html' => null,
            'processing_status' => 'fetched',
        ]);
    }

    public function test_new_booking_with_empty_body_is_marked_failed_and_alerts(): void
    {
        $row = $this->makeRow('Booking - S374926 - GYGTESTNULL01', null);

        $this->alertMock->shouldReceive('sendOpsAlert')
            ->once()
            ->with(\Mockery::on(fn ($text) => str_contains($text, 'GYGTESTNULL01') || str_contains($text, 'new_booking')));

        $this->artisan('gyg:process-emails')->assertSuccessful();

        $row->refresh();
        $this->assertSame('new_booking', $row->email_type);
        $this->assertSame('failed', $row->processing_status);
        $this->assertSame('Body fetch timed out — re-fetch required', $row->parse_error);
        $this->assertSame(1, $row->parse_attempts, 'parse_attempts must increment on guard hit');
        $this->assertNull($row->booking_inquiry_id, 'must NOT auto-apply with no body');
    }

    public function test_failed_empty_body_row_not_realerted_on_subsequent_runs(): void
    {
        // Idempotency contract: once flagged 'failed', the row is excluded from
        // gyg:process-emails' default query (processing_status='fetched' only).
        // A second cron tick must NOT re-fire the operator alert.
        $row = $this->makeRow('Booking - S374926 - GYGTESTNULL05', null);

        $this->alertMock->shouldReceive('sendOpsAlert')->once();

        $this->artisan('gyg:process-emails')->assertSuccessful();
        $this->artisan('gyg:process-emails')->assertSuccessful(); // second tick

        $row->refresh();
        $this->assertSame('failed', $row->processing_status);
    }

    public function test_cancellation_with_empty_body_is_marked_failed_and_alerts(): void
    {
        $row = $this->makeRow('A booking has been canceled - S374926 - GYGTESTNULL02', '');

        $this->alertMock->shouldReceive('sendOpsAlert')->once();

        $this->artisan('gyg:process-emails')->assertSuccessful();

        $row->refresh();
        $this->assertSame('cancellation', $row->email_type);
        $this->assertSame('failed', $row->processing_status);
        $this->assertSame('Body fetch timed out — re-fetch required', $row->parse_error);
    }

    public function test_amendment_with_empty_body_is_marked_failed_and_alerts(): void
    {
        $row = $this->makeRow('Booking detail change: - S374926 - GYGTESTNULL03', '   ');

        $this->alertMock->shouldReceive('sendOpsAlert')->once();

        $this->artisan('gyg:process-emails')->assertSuccessful();

        $row->refresh();
        $this->assertSame('amendment', $row->email_type);
        $this->assertSame('failed', $row->processing_status);
    }

    public function test_unknown_subject_with_empty_body_remains_skipped_no_alert(): void
    {
        // Pre-existing behavior must be preserved: unknown noise should still
        // skip silently, no Telegram spam.
        $row = $this->makeRow('Random newsletter from GetYourGuide', null);

        $this->alertMock->shouldNotReceive('sendOpsAlert');

        $this->artisan('gyg:process-emails')->assertSuccessful();

        $row->refresh();
        $this->assertSame('unknown', $row->email_type);
        $this->assertSame('skipped', $row->processing_status);
    }

    public function test_normal_new_booking_with_full_body_still_processes(): void
    {
        // Smoke test: existing happy-path must keep working.
        $body = <<<'BODY'
Hi Supply Partner, great news!
Your offer has been booked:

Samarkand: Test Tour

Group Tour Variant

Reference numbergygtestnull04

DateApril 19, 2026 9:00 AM

Number of participants2 x Adults (Age 0 - 99)

Main customerJane Doe customer-test@reply.getyourguide.com
Phone: +12345678
Language: English

Price$ 100.00open booking
BODY;

        $row = $this->makeRow('Booking - S374926 - GYGTESTNULL04', $body);

        $this->alertMock->shouldNotReceive('sendOpsAlert');

        $this->artisan('gyg:process-emails')->assertSuccessful();

        $row->refresh();
        $this->assertSame('new_booking', $row->email_type);
        $this->assertNotSame('failed', $row->processing_status);
        $this->assertNotSame('skipped', $row->processing_status);
        $this->assertSame('GYGTESTNULL04', $row->gyg_booking_reference);
    }
}
