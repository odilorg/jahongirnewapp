<?php

declare(strict_types=1);

namespace Tests\Feature\Feedback;

use App\Actions\Feedback\SendManualInternalFeedbackRequestAction;
use App\Models\BookingInquiry;
use App\Models\TourFeedback;
use App\Services\Messaging\SendResult;
use App\Services\Messaging\WhatsAppSender;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 2026-05-07 — manual Day-1 internal feedback request invariants.
 *
 * Symmetric with ManualTripAdvisorReviewRequestTest. Pins the
 * operator-driven send path:
 *   1. Composed message contains the /feedback/{token} URL on our
 *      domain (not a public TripAdvisor / Google URL — those only
 *      appear after the guest submits a positive rating).
 *   2. Successful WhatsApp send → feedback_request_sent_at stamped
 *      AND a TourFeedback row was created.
 *   3. Both channels failing → feedback_request_sent_at stays NULL
 *      AND the orphan TourFeedback row is cleaned up so a retry
 *      generates a fresh token + opener.
 *   4. Failure result carries a reason string for the operator
 *      notification.
 *   5. Auto-cron tour:send-review-requests is no longer scheduled
 *      (regression guard — symmetric with the public-review pin).
 */
final class ManualInternalFeedbackRequestTest extends TestCase
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
    public function message_contains_internal_feedback_url_on_our_domain(): void
    {
        $this->mock(WhatsAppSender::class, function ($mock) {
            $mock->shouldReceive('normalizePhone')->andReturn('998901234567');
            $mock->shouldReceive('send')->andReturn(SendResult::ok('whatsapp'));
        });

        $inquiry = $this->makeInquiry();
        $result  = app(SendManualInternalFeedbackRequestAction::class)->execute($inquiry, 1);

        $this->assertTrue($result['sent']);
        $this->assertStringContainsString('/feedback/', $result['text'],
            'composed message must carry the internal feedback URL');
        $this->assertStringNotContainsString('tripadvisor.com', $result['text'],
            'public TripAdvisor URL must not appear in the internal feedback message');
        $this->assertStringNotContainsString('g.page', $result['text'],
            'Google review URL must not appear in the internal feedback message');
    }

    /** @test */
    public function successful_whatsapp_send_stamps_feedback_request_sent_at(): void
    {
        $this->mock(WhatsAppSender::class, function ($mock) {
            $mock->shouldReceive('normalizePhone')->andReturn('998901234567');
            $mock->shouldReceive('send')->andReturn(SendResult::ok('whatsapp'));
        });

        $inquiry = $this->makeInquiry(['feedback_request_sent_at' => null]);
        $result  = app(SendManualInternalFeedbackRequestAction::class)->execute($inquiry, 1);

        $this->assertTrue($result['sent']);
        $this->assertSame('whatsapp', $result['channel']);
        $this->assertNotNull($result['feedback_id']);
        $this->assertNotNull($inquiry->fresh()->feedback_request_sent_at,
            'success must stamp feedback_request_sent_at via forceFill');

        $feedback = TourFeedback::find($result['feedback_id']);
        $this->assertNotNull($feedback, 'TourFeedback row must exist on success');
        $this->assertSame($inquiry->id, $feedback->inquiry_id);
        $this->assertSame('whatsapp', $feedback->source);
    }

    /** @test */
    public function both_channels_failing_does_not_stamp_and_cleans_orphan_feedback(): void
    {
        $this->mock(WhatsAppSender::class, function ($mock) {
            $mock->shouldReceive('normalizePhone')->andReturn(null); // unparseable phone
        });

        $inquiry = $this->makeInquiry([
            'customer_phone'           => 'not-a-phone',
            'customer_email'           => '', // also no email
            'feedback_request_sent_at' => null,
        ]);

        $countBefore = TourFeedback::count();
        $result      = app(SendManualInternalFeedbackRequestAction::class)->execute($inquiry);

        $this->assertFalse($result['sent']);
        $this->assertNull($result['channel']);
        $this->assertNotNull($result['reason']);
        $this->assertNull($inquiry->fresh()->feedback_request_sent_at,
            'failure must NOT stamp — operator retries after fixing contact info');
        $this->assertSame($countBefore, TourFeedback::count(),
            'orphan TourFeedback must be deleted so retry generates a fresh token');
    }

    /** @test */
    public function whatsapp_failure_does_not_stamp_when_email_also_missing(): void
    {
        $this->mock(WhatsAppSender::class, function ($mock) {
            $mock->shouldReceive('normalizePhone')->andReturn('998901234567');
            $mock->shouldReceive('send')->andReturn(SendResult::fail('whatsapp', 'API offline'));
        });

        $inquiry = $this->makeInquiry([
            'customer_email'           => '',
            'feedback_request_sent_at' => null,
        ]);

        $result = app(SendManualInternalFeedbackRequestAction::class)->execute($inquiry, 1);

        $this->assertFalse($result['sent']);
        $this->assertNull($result['channel']);
        $this->assertNull($inquiry->fresh()->feedback_request_sent_at);
    }

    /** @test */
    public function auto_cron_is_no_longer_scheduled(): void
    {
        // 2026-05-07 — symmetric with the public-review regression
        // guard. The artisan command file is intentionally retained
        // for manual batch backfills, but tour:send-review-requests
        // MUST NOT appear in any active schedule entry.
        $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);
        $events   = $schedule->events();
        foreach ($events as $event) {
            $command = (string) $event->command;
            $this->assertStringNotContainsString(
                'tour:send-review-requests',
                $command,
                'auto Day-1 internal feedback cron must remain disabled (manual-only by 2026-05-07 business decision)'
            );
        }
    }
}
