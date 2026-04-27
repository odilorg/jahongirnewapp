<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendTourReminderJob;
use App\Models\BookingInquiry;
use App\Services\Messaging\SendResult;
use App\Services\Messaging\WhatsAppSender;
use App\Services\TourReminderDispatcher;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Reminder pipeline contract tests.
 *
 * Covers the three reminder paths sharing TourReminderDispatcher:
 *   1. Daily 20:00 batch (TourSendReminders)
 *   2. Hourly catch-up (TourSendLateGuestReminders)
 *   3. On-confirm fast path (SendTourReminderJob via ConfirmBookingAction)
 *
 * The marker `guest_reminder_sent_at` is the single source of truth
 * across all three; collisions resolve to exactly one send.
 */
class TourReminderDispatcherTest extends TestCase
{
    use DatabaseTransactions;

    private function makeInquiry(array $overrides = []): BookingInquiry
    {
        $tomorrow = Carbon::now('Asia/Tashkent')->addDay();
        return BookingInquiry::create(array_merge([
            'reference'          => 'INQ-TEST-' . uniqid(),
            'source'             => 'website',
            'status'             => BookingInquiry::STATUS_CONFIRMED,
            'customer_name'      => 'Test Guest',
            'customer_phone'     => '+998901234567',
            'tour_name_snapshot' => 'Test Tour',
            'people_adults'      => 2,
            'people_children'    => 0,
            'travel_date'        => $tomorrow->toDateString(),
            'pickup_time'        => '09:00:00',
            'submitted_at'       => now(),
        ], $overrides));
    }

    private function bindWaSuccess(int $expectedCalls): void
    {
        $mock = $this->createMock(WhatsAppSender::class);
        $mock->method('normalizePhone')->willReturnCallback(
            fn (?string $p) => $p ? preg_replace('/[^0-9]/', '', $p) : null
        );
        $mock->expects($this->exactly($expectedCalls))
            ->method('send')
            ->willReturn(SendResult::ok('whatsapp'));
        $this->app->instance(WhatsAppSender::class, $mock);
    }

    private function bindWaFailure(): void
    {
        $mock = $this->createMock(WhatsAppSender::class);
        $mock->method('normalizePhone')->willReturnCallback(
            fn (?string $p) => $p ? preg_replace('/[^0-9]/', '', $p) : null
        );
        $mock->method('send')->willReturn(SendResult::fail('whatsapp', 'wa-api error'));
        $this->app->instance(WhatsAppSender::class, $mock);
    }

    public function test_first_dispatcher_call_sends_and_stamps_marker(): void
    {
        $inquiry = $this->makeInquiry();
        $this->bindWaSuccess(expectedCalls: 1);

        $result = app(TourReminderDispatcher::class)
            ->sendGuestReminder($inquiry, source: 'test');

        $this->assertTrue($result['ok']);
        $this->assertNotNull($result['lead_time_minutes']);
        $inquiry->refresh();
        $this->assertNotNull(
            $inquiry->guest_reminder_sent_at,
            'Marker MUST be stamped on success — that is the load-bearing idempotency guard'
        );
    }

    public function test_second_dispatcher_call_does_not_resend(): void
    {
        $inquiry = $this->makeInquiry();
        // Total expected = 1: first call sends, second short-circuits.
        $this->bindWaSuccess(expectedCalls: 1);

        $first  = app(TourReminderDispatcher::class)->sendGuestReminder($inquiry, source: 'test');
        $second = app(TourReminderDispatcher::class)->sendGuestReminder($inquiry->refresh(), source: 'test');

        $this->assertTrue($first['ok']);
        $this->assertFalse($second['ok']);
        $this->assertEquals('already_sent', $second['reason']);
    }

    public function test_inquiry_outside_24h_window_is_skipped(): void
    {
        $inquiry = $this->makeInquiry([
            'travel_date' => Carbon::now('Asia/Tashkent')->addDays(5)->toDateString(),
        ]);
        $this->bindWaSuccess(expectedCalls: 0);

        $result = app(TourReminderDispatcher::class)->sendGuestReminder($inquiry, source: 'test');

        $this->assertFalse($result['ok']);
        $this->assertEquals('out_of_window', $result['reason']);
        $this->assertNull($inquiry->fresh()->guest_reminder_sent_at);
    }

    public function test_wa_send_failure_keeps_marker_null_for_retry(): void
    {
        $inquiry = $this->makeInquiry();
        $this->bindWaFailure();

        $result = app(TourReminderDispatcher::class)->sendGuestReminder($inquiry, source: 'test');

        $this->assertFalse($result['ok']);
        $this->assertEquals('wa_failed', $result['reason']);
        $this->assertNull(
            $inquiry->fresh()->guest_reminder_sent_at,
            'Marker MUST stay null on failure so the next cron tick retries'
        );
    }

    public function test_unconfirmed_status_blocks_send(): void
    {
        $inquiry = $this->makeInquiry(['status' => BookingInquiry::STATUS_NEW]);
        $this->bindWaSuccess(expectedCalls: 0);

        $result = app(TourReminderDispatcher::class)->sendGuestReminder($inquiry, source: 'test');

        $this->assertFalse($result['ok']);
        $this->assertEquals('not_confirmed', $result['reason']);
    }

    public function test_confirm_action_dispatches_fast_path_job_when_within_24h(): void
    {
        Bus::fake([SendTourReminderJob::class]);
        $inquiry = $this->makeInquiry([
            'status'      => BookingInquiry::STATUS_NEW,
            'travel_date' => Carbon::now('Asia/Tashkent')->addHours(12)->toDateString(),
            'pickup_time' => Carbon::now('Asia/Tashkent')->addHours(12)->format('H:i:s'),
        ]);

        app(\App\Actions\Inquiry\ConfirmBookingAction::class)
            ->execute($inquiry, 'paid offline', 'manual');

        Bus::assertDispatched(SendTourReminderJob::class);
    }

    public function test_confirm_action_does_not_dispatch_fast_path_when_far_out(): void
    {
        Bus::fake([SendTourReminderJob::class]);
        $inquiry = $this->makeInquiry([
            'status'      => BookingInquiry::STATUS_NEW,
            'travel_date' => Carbon::now('Asia/Tashkent')->addDays(7)->toDateString(),
        ]);

        app(\App\Actions\Inquiry\ConfirmBookingAction::class)
            ->execute($inquiry, 'paid offline', 'manual');

        Bus::assertNotDispatched(SendTourReminderJob::class);
    }
}
