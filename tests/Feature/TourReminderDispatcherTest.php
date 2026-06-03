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
 * Guest reminder anti-duplicate guard contract tests.
 *
 * Covers the central TourReminderDispatcher guard shared by all three
 * reminder paths (daily batch, hourly catch-up, on-confirm fast path).
 *
 * Design rule: the dispatcher is the single source of truth. Caller
 * queries may be broad, but only the dispatcher decides whether a
 * send actually fires. Every test that mocks the WhatsAppSender
 * asserts the exact expected call count to catch duplicate sends.
 */
class TourReminderDispatcherTest extends TestCase
{
    use DatabaseTransactions;

    // ── Helpers ──────────────────────────────────────────────────────────

    private function makeInquiry(array $overrides = []): BookingInquiry
    {
        $tomorrow = Carbon::now('Asia/Tashkent')->addDay();

        return BookingInquiry::create(array_merge([
            'reference' => 'INQ-TEST-'.uniqid(),
            'source' => 'website',
            'status' => BookingInquiry::STATUS_CONFIRMED,
            'customer_name' => 'Test Guest',
            'customer_phone' => '+998901234567',
            'tour_name_snapshot' => 'Test Tour',
            'people_adults' => 2,
            'people_children' => 0,
            'travel_date' => $tomorrow->toDateString(),
            'pickup_time' => '09:00:00',
            'submitted_at' => now(),
        ], $overrides));
    }

    private function bindWaSuccess(int $expectedCalls = 1): void
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

    private function bindWaTimeout(): void
    {
        $mock = $this->createMock(WhatsAppSender::class);
        $mock->method('normalizePhone')->willReturnCallback(
            fn (?string $p) => $p ? preg_replace('/[^0-9]/', '', $p) : null
        );
        $mock->method('send')->willReturn(
            SendResult::fail('whatsapp', 'cURL error 28: Operation timed out after 15002 milliseconds', retryable: true)
        );
        $this->app->instance(WhatsAppSender::class, $mock);
    }

    private function bindWaFailure(string $error = 'wa-api returned 500'): void
    {
        $mock = $this->createMock(WhatsAppSender::class);
        $mock->method('normalizePhone')->willReturnCallback(
            fn (?string $p) => $p ? preg_replace('/[^0-9]/', '', $p) : null
        );
        $mock->method('send')->willReturn(SendResult::fail('whatsapp', $error));
        $this->app->instance(WhatsAppSender::class, $mock);
    }

    private function dispatcher(): TourReminderDispatcher
    {
        return app(TourReminderDispatcher::class);
    }

    // ── Happy path ───────────────────────────────────────────────────────

    public function test_success_stamps_sent_at_and_status(): void
    {
        $inquiry = $this->makeInquiry();
        $this->bindWaSuccess(expectedCalls: 1);

        $result = $this->dispatcher()->sendGuestReminder($inquiry, source: 'test');

        $this->assertTrue($result['ok']);
        $this->assertNotNull($result['lead_time_minutes']);

        $fresh = $inquiry->fresh();
        $this->assertNotNull($fresh->guest_reminder_sent_at, 'sent_at MUST be stamped on success');
        $this->assertEquals(BookingInquiry::REMINDER_STATUS_SENT, $fresh->guest_reminder_status);
        $this->assertNull($fresh->guest_reminder_last_error);
        $this->assertEquals(1, $fresh->guest_reminder_attempt_count);
    }

    // ── Already sent ─────────────────────────────────────────────────────

    public function test_already_sent_via_sent_at_never_resends(): void
    {
        $inquiry = $this->makeInquiry();
        $this->bindWaSuccess(expectedCalls: 1);

        // First: sends
        $first = $this->dispatcher()->sendGuestReminder($inquiry, source: 'test');
        $this->assertTrue($first['ok']);

        // Second: blocked by sent_at
        $second = $this->dispatcher()->sendGuestReminder($inquiry->fresh(), source: 'test');
        $this->assertFalse($second['ok']);
        $this->assertEquals('already_sent', $second['reason']);
    }

    public function test_already_sent_via_status_never_resends(): void
    {
        // Simulate an inquiry that has status=sent but sent_at is somehow
        // null (should not happen in practice, but the guard handles it).
        $inquiry = $this->makeInquiry([
            'guest_reminder_status' => BookingInquiry::REMINDER_STATUS_SENT,
            'guest_reminder_sent_at' => null,
        ]);
        $this->bindWaSuccess(expectedCalls: 0);

        $result = $this->dispatcher()->sendGuestReminder($inquiry, source: 'test');

        $this->assertFalse($result['ok']);
        $this->assertEquals('already_sent', $result['reason']);
    }

    // ── Timeout → unknown ────────────────────────────────────────────────

    public function test_timeout_marks_status_unknown_not_sent(): void
    {
        $inquiry = $this->makeInquiry();
        $this->bindWaTimeout();

        $result = $this->dispatcher()->sendGuestReminder($inquiry, source: 'test');

        $this->assertFalse($result['ok']);
        $this->assertEquals('wa_timeout', $result['reason']);

        $fresh = $inquiry->fresh();
        $this->assertNull($fresh->guest_reminder_sent_at, 'sent_at MUST stay null on timeout');
        $this->assertEquals(BookingInquiry::REMINDER_STATUS_UNKNOWN, $fresh->guest_reminder_status);
        $this->assertNotNull($fresh->guest_reminder_last_attempted_at, 'last_attempted_at MUST be set as throttle anchor');
        $this->assertEquals(1, $fresh->guest_reminder_attempt_count);
        $this->assertStringContainsString('timeout', (string) $fresh->guest_reminder_last_error);
    }

    public function test_timeout_blocks_retry_for_4_hours(): void
    {
        $inquiry = $this->makeInquiry();
        $this->bindWaTimeout();

        // First attempt: timeout → unknown
        $first = $this->dispatcher()->sendGuestReminder($inquiry, source: 'test');
        $this->assertEquals('wa_timeout', $first['reason']);

        // Re-bind success — but the guard should block before calling send()
        $this->bindWaSuccess(expectedCalls: 0);

        // Second attempt immediately after timeout: blocked by throttle
        $second = $this->dispatcher()->sendGuestReminder($inquiry->fresh(), source: 'test');
        $this->assertFalse($second['ok']);
        $this->assertEquals('throttled', $second['reason'],
            'Must NOT resend within 4 hours of an unknown outcome');
    }

    public function test_after_throttle_window_retry_is_allowed(): void
    {
        $inquiry = $this->makeInquiry();
        $this->bindWaTimeout();

        // First: timeout → unknown
        $this->dispatcher()->sendGuestReminder($inquiry, source: 'test');

        // Simulate 4+ hours passing by back-dating last_attempted_at
        $inquiry->forceFill([
            'guest_reminder_last_attempted_at' => Carbon::now('Asia/Tashkent')->subHours(5),
        ])->save();

        // Second attempt after throttle: allowed
        $this->bindWaSuccess(expectedCalls: 1);

        $inquiry->refresh();
        $second = $this->dispatcher()->sendGuestReminder($inquiry, source: 'test');
        $this->assertTrue($second['ok'], 'Should be allowed to retry after 4h throttle window');

        $fresh = $inquiry->fresh();
        $this->assertNotNull($fresh->guest_reminder_sent_at);
        $this->assertEquals(BookingInquiry::REMINDER_STATUS_SENT, $fresh->guest_reminder_status);
        $this->assertEquals(2, $fresh->guest_reminder_attempt_count);
    }

    // ── Clear failure ────────────────────────────────────────────────────

    public function test_clear_failure_marks_status_failed(): void
    {
        $inquiry = $this->makeInquiry();
        $this->bindWaFailure('wa-api returned 500');

        $result = $this->dispatcher()->sendGuestReminder($inquiry, source: 'test');

        $this->assertFalse($result['ok']);
        $this->assertEquals('wa_failed', $result['reason']);

        $fresh = $inquiry->fresh();
        $this->assertNull($fresh->guest_reminder_sent_at);
        $this->assertEquals(BookingInquiry::REMINDER_STATUS_FAILED, $fresh->guest_reminder_status);
        $this->assertEquals(1, $fresh->guest_reminder_attempt_count);
    }

    public function test_failed_retry_blocked_within_throttle_window(): void
    {
        $inquiry = $this->makeInquiry();
        $this->bindWaFailure('wa-api returned 500');

        $this->dispatcher()->sendGuestReminder($inquiry, source: 'test');

        // Immediate retry: blocked by throttle
        $this->bindWaSuccess(expectedCalls: 0);
        $second = $this->dispatcher()->sendGuestReminder($inquiry->fresh(), source: 'test');
        $this->assertEquals('throttled', $second['reason']);
    }

    // ── Suppression after max attempts ───────────────────────────────────

    public function test_max_attempts_unknown_suppresses(): void
    {
        $inquiry = $this->makeInquiry();
        $this->bindWaTimeout();

        // Attempt 1: timeout → unknown
        $this->dispatcher()->sendGuestReminder($inquiry, source: 'test');

        // Simulate throttle window passed
        $inquiry->forceFill([
            'guest_reminder_last_attempted_at' => Carbon::now('Asia/Tashkent')->subHours(5),
        ])->save();

        // Attempt 2: timeout again → suppressed (max=2)
        $this->bindWaTimeout();
        $second = $this->dispatcher()->sendGuestReminder($inquiry->fresh(), source: 'test');

        $this->assertEquals('wa_timeout', $second['reason']);

        // Attempt 3: should be suppressed (even though throttle window passed for attempt 2)
        $inquiry->forceFill([
            'guest_reminder_last_attempted_at' => Carbon::now('Asia/Tashkent')->subHours(5),
        ])->save();
        $this->bindWaSuccess(expectedCalls: 0);

        $third = $this->dispatcher()->sendGuestReminder($inquiry->fresh(), source: 'test');

        $this->assertFalse($third['ok']);
        $this->assertEquals('suppressed', $third['reason'],
            'After 2 unknown/failed attempts, further automatic sends MUST be suppressed');

        $fresh = $inquiry->fresh();
        $this->assertEquals(BookingInquiry::REMINDER_STATUS_SUPPRESSED, $fresh->guest_reminder_status);
    }

    public function test_max_attempts_failed_also_suppresses(): void
    {
        $inquiry = $this->makeInquiry();

        // Pre-set: 2 failed attempts
        $inquiry->forceFill([
            'guest_reminder_attempt_count' => 2,
            'guest_reminder_status' => BookingInquiry::REMINDER_STATUS_FAILED,
            'guest_reminder_last_attempted_at' => Carbon::now('Asia/Tashkent')->subHours(5),
            'guest_reminder_last_error' => 'wa-api 500 (attempt 2)',
        ])->save();

        $this->bindWaSuccess(expectedCalls: 0);

        $result = $this->dispatcher()->sendGuestReminder($inquiry->fresh(), source: 'test');

        $this->assertFalse($result['ok']);
        $this->assertEquals('suppressed', $result['reason']);

        $fresh = $inquiry->fresh();
        $this->assertEquals(BookingInquiry::REMINDER_STATUS_SUPPRESSED, $fresh->guest_reminder_status);
        $this->assertStringContainsString('manual review required', (string) $fresh->guest_reminder_last_error);
    }

    // ── Stale sending guard ──────────────────────────────────────────────

    public function test_status_sending_with_recent_attempt_is_skipped(): void
    {
        $inquiry = $this->makeInquiry([
            'guest_reminder_status' => BookingInquiry::REMINDER_STATUS_SENDING,
            'guest_reminder_last_attempted_at' => Carbon::now('Asia/Tashkent')->subMinutes(5),
            'guest_reminder_attempt_count' => 1,
        ]);
        $this->bindWaSuccess(expectedCalls: 0);

        $result = $this->dispatcher()->sendGuestReminder($inquiry, source: 'test');

        $this->assertFalse($result['ok']);
        $this->assertEquals('currently_sending', $result['reason'],
            'Must skip when status=sending and last_attempted_at < 30 min ago');
    }

    // ── Deterministic idempotency key ────────────────────────────────────

    public function test_deterministic_idempotency_key_stays_same(): void
    {
        $inquiry = $this->makeInquiry();
        $this->bindWaTimeout();

        // First attempt
        $this->dispatcher()->sendGuestReminder($inquiry, source: 'test');
        $key1 = $inquiry->fresh()->guest_reminder_idempotency_key;
        $this->assertNotNull($key1);

        // Simulate throttle passed
        $inquiry->forceFill([
            'guest_reminder_last_attempted_at' => Carbon::now('Asia/Tashkent')->subHours(5),
        ])->save();

        // Second attempt — key must be identical
        $this->bindWaTimeout();
        $this->dispatcher()->sendGuestReminder($inquiry->fresh(), source: 'test');
        $key2 = $inquiry->fresh()->guest_reminder_idempotency_key;

        $this->assertEquals($key1, $key2, 'Idempotency key MUST be deterministic across retries');
    }

    public function test_idempotency_key_format_is_correct(): void
    {
        $inquiry = $this->makeInquiry();
        $expectedKey = 'guest-reminder:'.$inquiry->id.':'.$inquiry->reference;
        $this->assertEquals($expectedKey, $inquiry->reminderIdempotencyKey());
    }

    // ── Status guards ────────────────────────────────────────────────────

    public function test_unconfirmed_status_blocks_send(): void
    {
        $inquiry = $this->makeInquiry(['status' => BookingInquiry::STATUS_NEW]);
        $this->bindWaSuccess(expectedCalls: 0);

        $result = $this->dispatcher()->sendGuestReminder($inquiry, source: 'test');

        $this->assertFalse($result['ok']);
        $this->assertEquals('not_confirmed', $result['reason']);
    }

    public function test_outside_24h_window_is_skipped(): void
    {
        $inquiry = $this->makeInquiry([
            'travel_date' => Carbon::now('Asia/Tashkent')->addDays(5)->toDateString(),
        ]);
        $this->bindWaSuccess(expectedCalls: 0);

        $result = $this->dispatcher()->sendGuestReminder($inquiry, source: 'test');

        $this->assertFalse($result['ok']);
        $this->assertEquals('out_of_window', $result['reason']);
        $this->assertNull($inquiry->fresh()->guest_reminder_sent_at);
    }

    public function test_no_phone_skipped(): void
    {
        $inquiry = $this->makeInquiry(['customer_phone' => '']);
        $this->bindWaSuccess(expectedCalls: 0);

        $result = $this->dispatcher()->sendGuestReminder($inquiry, source: 'test');

        $this->assertFalse($result['ok']);
        $this->assertEquals('no_phone', $result['reason']);
    }

    // ── Fast-path job still delegates to dispatcher ───────────────────────

    public function test_confirm_action_dispatches_fast_path_job_when_within_24h(): void
    {
        Bus::fake([SendTourReminderJob::class]);
        $inquiry = $this->makeInquiry([
            'status' => BookingInquiry::STATUS_NEW,
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
            'status' => BookingInquiry::STATUS_NEW,
            'travel_date' => Carbon::now('Asia/Tashkent')->addDays(7)->toDateString(),
        ]);

        app(\App\Actions\Inquiry\ConfirmBookingAction::class)
            ->execute($inquiry, 'paid offline', 'manual');

        Bus::assertNotDispatched(SendTourReminderJob::class);
    }

    public function test_confirm_action_blocked_when_already_sent(): void
    {
        Bus::fake([SendTourReminderJob::class]);
        $inquiry = $this->makeInquiry([
            'status' => BookingInquiry::STATUS_NEW,
            'guest_reminder_sent_at' => Carbon::now('Asia/Tashkent'),
            'guest_reminder_status' => BookingInquiry::REMINDER_STATUS_SENT,
            'travel_date' => Carbon::now('Asia/Tashkent')->addHours(12)->toDateString(),
            'pickup_time' => Carbon::now('Asia/Tashkent')->addHours(12)->format('H:i:s'),
        ]);

        app(\App\Actions\Inquiry\ConfirmBookingAction::class)
            ->execute($inquiry, 'paid offline', 'manual');

        Bus::assertNotDispatched(SendTourReminderJob::class);
    }
}
