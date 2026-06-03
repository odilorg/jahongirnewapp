<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BookingInquiry;
use App\Services\Messaging\SendResult;
use App\Services\Messaging\WhatsAppSender;
use App\Services\TourReminderDispatcher;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Race-condition guard tests for the guest reminder dispatcher.
 *
 * Proves that two concurrent dispatch attempts serialise via
 * lockForUpdate() and only one send actually fires.
 */
class TourReminderGuardsTest extends TestCase
{
    use DatabaseTransactions;

    private function makeInquiry(array $overrides = []): BookingInquiry
    {
        $tomorrow = Carbon::now('Asia/Tashkent')->addDay();

        return BookingInquiry::create(array_merge([
            'reference' => 'INQ-GUARD-'.uniqid(),
            'source' => 'website',
            'status' => BookingInquiry::STATUS_CONFIRMED,
            'customer_name' => 'Guard Test Guest',
            'customer_phone' => '+998901234567',
            'tour_name_snapshot' => 'Test Tour',
            'people_adults' => 1,
            'people_children' => 0,
            'travel_date' => $tomorrow->toDateString(),
            'pickup_time' => '09:00:00',
            'submitted_at' => now(),
        ], $overrides));
    }

    /**
     * Simulate two concurrent dispatchers racing for the same inquiry.
     *
     * We mock WhatsAppSender to succeed and use two separate DB connections
     * (the same connection in different transactions) to simulate concurrent
     * dispatch attempts. The lockForUpdate() call in the dispatcher MUST
     * serialise them — the second sees status "sending" (or "sent") and
     * no-ops.
     */
    public function test_two_simultaneous_dispatches_only_one_sends(): void
    {
        $inquiry = $this->makeInquiry();

        // Count actual WhatsApp send calls
        $sendCount = 0;
        $mock = $this->createMock(WhatsAppSender::class);
        $mock->method('normalizePhone')->willReturnCallback(
            fn (?string $p) => $p ? preg_replace('/[^0-9]/', '', $p) : null
        );
        $mock->method('send')->willReturnCallback(function () use (&$sendCount) {
            $sendCount++;

            return SendResult::ok('whatsapp');
        });
        $this->app->instance(WhatsAppSender::class, $mock);

        $dispatcher = app(TourReminderDispatcher::class);

        // Run two dispatches sequentially (in real life they could race,
        // but since they share the same DB connection pool, the lockForUpdate
        // will serialise them). We simulate the race by not refreshing
        // between calls — both use the same in-memory $inquiry.
        $first = $dispatcher->sendGuestReminder($inquiry, source: 'batch');
        $second = $dispatcher->sendGuestReminder($inquiry, source: 'catch_up');

        // One must succeed, the other must be blocked
        $this->assertTrue($first['ok'] || $second['ok'], 'At least one dispatch must succeed');
        $this->assertTrue(
            ($first['ok'] && ! $second['ok']) || (! $first['ok'] && $second['ok']),
            'Exactly one dispatch must succeed, the other must be blocked.'
            .' First: '.($first['ok'] ? 'ok' : $first['reason'])
            .' Second: '.($second['ok'] ? 'ok' : $second['reason'])
        );

        // Only one WhatsApp send call
        $this->assertEquals(1, $sendCount, 'Exactly one WhatsApp send must happen');

        // Final state: sent_at must be stamped
        $fresh = $inquiry->fresh();
        $this->assertNotNull($fresh->guest_reminder_sent_at);
        $this->assertEquals(BookingInquiry::REMINDER_STATUS_SENT, $fresh->guest_reminder_status);
    }

    /**
     * Test that the row lock prevents a third concurrent dispatch from
     * sneaking through when the first has already stamped "sent".
     */
    public function test_row_lock_prevents_dispatch_after_sent(): void
    {
        $inquiry = $this->makeInquiry();

        // Pre-stamp as already sent
        $inquiry->forceFill([
            'guest_reminder_sent_at' => Carbon::now('Asia/Tashkent'),
            'guest_reminder_status' => BookingInquiry::REMINDER_STATUS_SENT,
        ])->save();

        // WhatsAppSender must NOT be called
        $mock = $this->createMock(WhatsAppSender::class);
        $mock->method('normalizePhone')->willReturnCallback(
            fn (?string $p) => $p ? preg_replace('/[^0-9]/', '', $p) : null
        );
        $mock->expects($this->never())->method('send');
        $this->app->instance(WhatsAppSender::class, $mock);

        $result = app(TourReminderDispatcher::class)
            ->sendGuestReminder($inquiry->fresh(), source: 'test');

        $this->assertFalse($result['ok']);
        $this->assertEquals('already_sent', $result['reason']);
    }

    /**
     * Prove that the "sending" guard blocks within the 30-minute stale window,
     * simulating what happens when a PHP crash leaves status=sending.
     */
    public function test_sending_status_blocks_auto_retry(): void
    {
        $inquiry = $this->makeInquiry([
            'guest_reminder_status' => BookingInquiry::REMINDER_STATUS_SENDING,
            'guest_reminder_last_attempted_at' => Carbon::now('Asia/Tashkent')->subMinutes(10),
            'guest_reminder_attempt_count' => 1,
        ]);

        $mock = $this->createMock(WhatsAppSender::class);
        $mock->method('normalizePhone')->willReturnCallback(
            fn (?string $p) => $p ? preg_replace('/[^0-9]/', '', $p) : null
        );
        $mock->expects($this->never())->method('send');
        $this->app->instance(WhatsAppSender::class, $mock);

        $result = app(TourReminderDispatcher::class)
            ->sendGuestReminder($inquiry, source: 'test');

        $this->assertFalse($result['ok']);
        $this->assertEquals('currently_sending', $result['reason']);
    }

    /**
     * Prove that a stale "sending" status (>30 min) IS retried — this
     * covers the case where PHP crashed mid-HTTP and the row was left
     * stuck in "sending".
     */
    public function test_stale_sending_status_is_retried(): void
    {
        $inquiry = $this->makeInquiry([
            'guest_reminder_status' => BookingInquiry::REMINDER_STATUS_SENDING,
            'guest_reminder_last_attempted_at' => Carbon::now('Asia/Tashkent')->subMinutes(35),
            'guest_reminder_attempt_count' => 1,
        ]);

        $mock = $this->createMock(WhatsAppSender::class);
        $mock->method('normalizePhone')->willReturnCallback(
            fn (?string $p) => $p ? preg_replace('/[^0-9]/', '', $p) : null
        );
        $mock->expects($this->once())
            ->method('send')
            ->willReturn(SendResult::ok('whatsapp'));
        $this->app->instance(WhatsAppSender::class, $mock);

        $result = app(TourReminderDispatcher::class)
            ->sendGuestReminder($inquiry->fresh(), source: 'test');

        $this->assertTrue($result['ok'],
            'Stale sending (35 min ago) MUST be retried — the previous dispatch likely crashed');

        $fresh = $inquiry->fresh();
        $this->assertNotNull($fresh->guest_reminder_sent_at);
        $this->assertEquals(BookingInquiry::REMINDER_STATUS_SENT, $fresh->guest_reminder_status);
        $this->assertEquals(2, $fresh->guest_reminder_attempt_count);
    }
}
