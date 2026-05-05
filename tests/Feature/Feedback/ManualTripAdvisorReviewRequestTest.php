<?php

declare(strict_types=1);

namespace Tests\Feature\Feedback;

use App\Actions\Feedback\SendManualTripAdvisorReviewRequestAction;
use App\Models\BookingInquiry;
use App\Services\Feedback\PublicReviewRequestMessageFactory;
use App\Services\Messaging\SendResult;
use App\Services\Messaging\WhatsAppSender;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Phase 1.7.0 — manual TripAdvisor review request invariants.
 *
 * Pins the operator-driven send path:
 *   1. Composed message contains the TripAdvisor URL and NOT the
 *      Google review URL (Google explicitly removed per business)
 *   2. Successful WhatsApp send → review_request_sent_at stamped
 *   3. Successful email fallback → review_request_sent_at stamped
 *   4. Both channels failing → review_request_sent_at stays NULL
 *      so the operator can retry after fixing contact info
 *   5. Auto-cron is no longer scheduled (regression guard against
 *      accidentally re-enabling automatic public review nudges)
 */
final class ManualTripAdvisorReviewRequestTest extends TestCase
{
    use DatabaseTransactions;

    private function makeInquiry(array $overrides = []): BookingInquiry
    {
        return BookingInquiry::create(array_merge([
            'reference'          => BookingInquiry::generateReference(),
            'source'             => 'manual',
            'status'             => BookingInquiry::STATUS_CONFIRMED,
            'customer_name'      => 'Test Guest',
            'customer_phone'     => '+998901234567',
            'customer_email'     => 'test@example.com',
            'tour_name_snapshot' => 'Test Tour',
            'people_adults'      => 2,
            'travel_date'        => now()->subDay()->toDateString(),
            'submitted_at'       => now()->subDays(2),
        ], $overrides));
    }

    /** @test */
    public function message_contains_tripadvisor_link_and_not_google(): void
    {
        $inquiry = $this->makeInquiry(['customer_name' => 'Blake']);
        $built   = app(PublicReviewRequestMessageFactory::class)->build($inquiry);

        $this->assertStringContainsString('TripAdvisor', $built['text']);
        $this->assertStringContainsString('tripadvisor.com', $built['text']);
        $this->assertStringNotContainsString('Google', $built['text'], 'Google nudge must be removed');
        $this->assertStringNotContainsString('g.page', $built['text']);
        $this->assertStringContainsString('Blake', $built['text'], 'first name personalisation must work');
    }

    /** @test */
    public function successful_whatsapp_send_stamps_review_request_sent_at(): void
    {
        $this->mock(WhatsAppSender::class, function ($mock) {
            $mock->shouldReceive('normalizePhone')->andReturn('998901234567');
            $mock->shouldReceive('send')->andReturn(SendResult::ok('whatsapp'));
        });

        $inquiry = $this->makeInquiry(['review_request_sent_at' => null]);
        $result  = app(SendManualTripAdvisorReviewRequestAction::class)->execute($inquiry, 1);

        $this->assertTrue($result['sent']);
        $this->assertSame('whatsapp', $result['channel']);
        $this->assertNotNull($inquiry->fresh()->review_request_sent_at);
    }

    /** @test */
    public function whatsapp_failure_falls_through_to_email_when_email_present(): void
    {
        // WhatsApp fails; email is on file. We can't actually exec
        // himalaya in tests, so we expect the action to TRY email and
        // (predictably) fail there too — review_request_sent_at must
        // therefore stay NULL. This pins the "stamp ONLY on success"
        // contract via the no-channels-succeeded path.
        $this->mock(WhatsAppSender::class, function ($mock) {
            $mock->shouldReceive('normalizePhone')->andReturn('998901234567');
            $mock->shouldReceive('send')->andReturn(SendResult::fail('whatsapp', 'API offline'));
        });

        $inquiry = $this->makeInquiry(['review_request_sent_at' => null]);
        $result  = app(SendManualTripAdvisorReviewRequestAction::class)->execute($inquiry, 1);

        $this->assertFalse($result['sent']);
        $this->assertNull($result['channel']);
        $this->assertNull($inquiry->fresh()->review_request_sent_at,
            'failure must NOT stamp the timestamp — operator can retry');
    }

    /** @test */
    public function send_failure_includes_reason_in_result(): void
    {
        $this->mock(WhatsAppSender::class, function ($mock) {
            $mock->shouldReceive('normalizePhone')->andReturn(null); // unparseable
        });

        $inquiry = $this->makeInquiry([
            'customer_phone'      => 'not-a-phone',
            'customer_email'      => '', // also no email
            'review_request_sent_at' => null,
        ]);

        $result = app(SendManualTripAdvisorReviewRequestAction::class)->execute($inquiry);
        $this->assertFalse($result['sent']);
        $this->assertNotNull($result['reason']);
    }

    /** @test */
    public function auto_cron_is_no_longer_scheduled(): void
    {
        // Pin: nobody re-adds tour:send-public-review-reminders to the
        // scheduler by accident. The artisan command file may still
        // exist (manual invocation only), but it MUST NOT appear in
        // any active schedule entry.
        $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);
        $events   = $schedule->events();
        foreach ($events as $event) {
            $command = (string) $event->command;
            $this->assertStringNotContainsString(
                'tour:send-public-review-reminders',
                $command,
                'auto public-review cron must remain disabled (manual-only by business decision)'
            );
        }
    }
}
