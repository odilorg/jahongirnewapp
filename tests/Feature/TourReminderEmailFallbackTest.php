<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\TourGuestReminderMail;
use App\Models\BookingInquiry;
use App\Services\Messaging\SendResult;
use App\Services\Messaging\WhatsAppSender;
use App\Services\TourReminderDispatcher;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Phase 28 — email-fallback channel for guest reminders.
 *
 * OTA bookings (GYG / Viator) arrive with no guest phone but a working
 * relay email. These tests assert the dispatcher routes them to email,
 * preserves exactly-once across channels, and falls back to an operator
 * alert when there is no contact at all.
 *
 * Real-sample shape: INQ-2026-000021 (Volker Plum, GYG, phone empty,
 * customer-…@reply.getyourguide.com).
 */
class TourReminderEmailFallbackTest extends TestCase
{
    use DatabaseTransactions;

    private function makeOtaInquiry(array $overrides = []): BookingInquiry
    {
        $tomorrow = Carbon::now('Asia/Tashkent')->addDay();

        return BookingInquiry::create(array_merge([
            'reference' => 'INQ-TEST-'.uniqid(),
            'source' => 'gyg',
            'status' => BookingInquiry::STATUS_CONFIRMED,
            'customer_name' => 'Volker Plum',
            'customer_phone' => '',
            'customer_email' => 'customer-niaaxwpazebk342d@reply.getyourguide.com',
            'tour_name_snapshot' => '798008 [YUT-01] 2-Day Desert Yurt Camp',
            'tour_slug' => 'yurt-camp-tour',
            'people_adults' => 1,
            'people_children' => 0,
            'travel_date' => $tomorrow->toDateString(),
            'pickup_time' => '09:00:00',
            'submitted_at' => now(),
        ], $overrides));
    }

    /** Mock WhatsAppSender so normalizePhone('') → null (no phone path). */
    private function bindWaNoPhone(): void
    {
        $mock = $this->createMock(WhatsAppSender::class);
        $mock->method('normalizePhone')->willReturnCallback(
            fn (?string $p) => ($p && trim($p) !== '') ? preg_replace('/[^0-9]/', '', $p) : null
        );
        // send() must NEVER be called on the email path.
        $mock->expects($this->never())->method('send');
        $this->app->instance(WhatsAppSender::class, $mock);
    }

    private function dispatcher(): TourReminderDispatcher
    {
        return app(TourReminderDispatcher::class);
    }

    /** @test */
    public function ota_booking_without_phone_is_reminded_by_email(): void
    {
        config(['tour_experience.email_fallback_enabled' => true]);
        Mail::fake();
        $this->bindWaNoPhone();

        $inquiry = $this->makeOtaInquiry();

        $result = $this->dispatcher()->sendGuestReminder($inquiry, 'test');

        $this->assertTrue($result['ok']);
        $this->assertSame('email', $result['channel']);

        Mail::assertSent(TourGuestReminderMail::class, function (TourGuestReminderMail $mail) use ($inquiry) {
            return $mail->hasTo('customer-niaaxwpazebk342d@reply.getyourguide.com')
                && $mail->reference === $inquiry->reference;
        });

        $inquiry->refresh();
        $this->assertNotNull($inquiry->guest_reminder_sent_at);
        $this->assertSame(BookingInquiry::REMINDER_STATUS_SENT, $inquiry->guest_reminder_status);

        $log = \DB::table('tour_reminder_logs')->where('booking_inquiry_id', $inquiry->id)->first();
        $this->assertSame('email', $log->channel);
        $this->assertSame('customer-niaaxwpazebk342d@reply.getyourguide.com', $log->phone);
        $this->assertSame('sent', $log->status);
    }

    /** @test */
    public function email_reminder_is_exactly_once_on_repeat_run(): void
    {
        config(['tour_experience.email_fallback_enabled' => true]);
        Mail::fake();
        $this->bindWaNoPhone();

        $inquiry = $this->makeOtaInquiry();

        $first = $this->dispatcher()->sendGuestReminder($inquiry, 'test');
        $second = $this->dispatcher()->sendGuestReminder($inquiry->fresh(), 'test');

        $this->assertTrue($first['ok']);
        $this->assertFalse($second['ok']);
        $this->assertSame('already_sent', $second['reason']);

        Mail::assertSent(TourGuestReminderMail::class, 1);
    }

    /** @test */
    public function flag_off_does_not_send_email_and_alerts_operator(): void
    {
        config(['tour_experience.email_fallback_enabled' => false]);
        Mail::fake();
        Bus::fake();
        $this->bindWaNoPhone();

        $inquiry = $this->makeOtaInquiry();

        $result = $this->dispatcher()->sendGuestReminder($inquiry, 'test');

        $this->assertFalse($result['ok']);
        $this->assertSame('no_contact', $result['reason']);
        Mail::assertNothingSent();

        $inquiry->refresh();
        $this->assertSame(BookingInquiry::REMINDER_STATUS_SUPPRESSED, $inquiry->guest_reminder_status);
    }

    /** @test */
    public function neither_phone_nor_email_suppresses_after_one_alert(): void
    {
        config(['tour_experience.email_fallback_enabled' => true]);
        Mail::fake();
        Bus::fake();
        $this->bindWaNoPhone();

        $inquiry = $this->makeOtaInquiry(['customer_email' => null]);

        $result = $this->dispatcher()->sendGuestReminder($inquiry, 'test');

        $this->assertFalse($result['ok']);
        $this->assertSame('no_contact', $result['reason']);
        Mail::assertNothingSent();

        $inquiry->refresh();
        $this->assertSame(BookingInquiry::REMINDER_STATUS_SUPPRESSED, $inquiry->guest_reminder_status);

        // Second run is a no-op (suppressed fast-path), no re-alert.
        $second = $this->dispatcher()->sendGuestReminder($inquiry->fresh(), 'test');
        $this->assertFalse($second['ok']);
    }

    /** @test */
    public function invalid_email_is_treated_as_no_contact(): void
    {
        config(['tour_experience.email_fallback_enabled' => true]);
        Mail::fake();
        Bus::fake();
        $this->bindWaNoPhone();

        $inquiry = $this->makeOtaInquiry(['customer_email' => 'not-an-email']);

        $result = $this->dispatcher()->sendGuestReminder($inquiry, 'test');

        $this->assertFalse($result['ok']);
        $this->assertSame('no_contact', $result['reason']);
        Mail::assertNothingSent();
    }
}
