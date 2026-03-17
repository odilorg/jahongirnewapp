<?php

namespace Tests\Unit;

use App\Models\GygInboundEmail;
use App\Services\GygNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GygNotifierTest extends TestCase
{
    use RefreshDatabase;

    private GygNotifier $notifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notifier = new GygNotifier();
    }

    private function createEmail(array $overrides = []): GygInboundEmail
    {
        return GygInboundEmail::create(array_merge([
            'email_message_id'      => '<notify-test-' . uniqid() . '@test.com>',
            'email_from'            => 'do-not-reply@notification.getyourguide.com',
            'email_subject'         => 'Booking - S374926 - GYGTEST',
            'email_date'            => now(),
            'body_text'             => 'test',
            'email_type'            => 'new_booking',
            'processing_status'     => 'applied',
            'gyg_booking_reference' => 'GYGTEST123',
            'tour_name'             => 'Test Tour',
            'guest_name'            => 'Jane Doe',
            'travel_date'           => '2026-05-01',
            'pax'                   => 2,
        ], $overrides));
    }

    public function test_sends_notification_for_new_booking(): void
    {
        Http::fake([
            '127.0.0.1:8766/*' => Http::response(['ok' => true, 'msg_id' => 1], 200),
        ]);

        $email = $this->createEmail();
        $sent = $this->notifier->notifyIfNeeded($email, 'new_booking', ['booking_id' => 42]);

        $this->assertTrue($sent);
        $email->refresh();
        $this->assertNotNull($email->notified_at);

        Http::assertSent(function ($request) {
            return str_contains($request->body(), 'New Booking Applied')
                && str_contains($request->body(), 'GYGTEST123');
        });
    }

    public function test_skips_notification_when_already_notified(): void
    {
        Http::fake();

        $email = $this->createEmail(['notified_at' => now()]);
        $sent = $this->notifier->notifyIfNeeded($email, 'new_booking');

        $this->assertTrue($sent); // returns true (already notified = success)
        Http::assertNothingSent();
    }

    public function test_notification_failure_does_not_set_notified_at(): void
    {
        Http::fake([
            '127.0.0.1:8766/*' => Http::response(['ok' => false], 500),
            'api.telegram.org/*' => Http::response(['ok' => false], 500),
        ]);

        // Set a bot token so fallback is attempted
        config(['services.driver_guide_bot.token' => 'test-token']);

        $email = $this->createEmail();
        $sent = $this->notifier->notifyIfNeeded($email, 'new_booking');

        $this->assertFalse($sent);
        $email->refresh();
        $this->assertNull($email->notified_at);
    }

    public function test_notification_contains_cancellation_details(): void
    {
        Http::fake([
            '127.0.0.1:8766/*' => Http::response(['ok' => true, 'msg_id' => 1], 200),
        ]);

        $email = $this->createEmail(['email_type' => 'cancellation']);
        $this->notifier->notifyIfNeeded($email, 'cancellation', ['booking_id' => 99]);

        Http::assertSent(function ($request) {
            return str_contains($request->body(), 'Cancelled')
                && str_contains($request->body(), 'Jane Doe');
        });
    }

    public function test_notification_contains_needs_review_reason(): void
    {
        Http::fake([
            '127.0.0.1:8766/*' => Http::response(['ok' => true, 'msg_id' => 1], 200),
        ]);

        $email = $this->createEmail(['processing_status' => 'needs_review']);
        $this->notifier->notifyIfNeeded($email, 'needs_review', [
            'reason' => 'Missing required field: option_title',
        ]);

        Http::assertSent(function ($request) {
            return str_contains($request->body(), 'Needs Review')
                && str_contains($request->body(), 'Missing required field');
        });
    }
}
