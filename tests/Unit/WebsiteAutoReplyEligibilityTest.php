<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\BookingInquiry;
use App\Services\Messaging\WhatsAppSender;
use App\Services\WebsiteAutoReplyService;
use Mockery;
use Tests\TestCase;

/**
 * Tests that WebsiteAutoReplyService only sends when all guard
 * conditions are met and silently skips otherwise.
 */
class WebsiteAutoReplyEligibilityTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeInquiry(array $overrides = []): BookingInquiry
    {
        $inquiry = new BookingInquiry();
        $inquiry->forceFill(array_merge([
            'source'         => BookingInquiry::SOURCE_WEBSITE,
            'customer_phone' => '+1234567890',
            'status'         => BookingInquiry::STATUS_NEW,
            'travel_date'    => now()->addDays(5),
            'created_at'     => now(),
            'customer_name'  => 'Test Guest',
            'tour_name_snapshot' => 'Test Tour',
            'people_adults'  => 2,
            'people_children'=> 0,
        ], $overrides));

        return $inquiry;
    }

    public function test_eligible_website_inquiry_triggers_send(): void
    {
        $wa = Mockery::mock(WhatsAppSender::class);
        $wa->shouldReceive('normalizePhone')->andReturn('1234567890');
        $wa->shouldReceive('send')->once()->andReturn(
            \App\Services\Messaging\SendResult::ok('whatsapp')
        );

        $service = new WebsiteAutoReplyService($wa);
        $inquiry = $this->makeInquiry();

        $service->sendIfEligible($inquiry);
        $this->assertTrue(true); // verifying send was called (Mockery assertion)
    }

    public function test_gyg_source_does_not_trigger_send(): void
    {
        $wa = Mockery::mock(WhatsAppSender::class);
        $wa->shouldNotReceive('send');

        $service = new WebsiteAutoReplyService($wa);
        $inquiry = $this->makeInquiry(['source' => BookingInquiry::SOURCE_GYG]);

        $service->sendIfEligible($inquiry);
        $this->assertTrue(true);
    }

    public function test_whatsapp_source_does_not_trigger_send(): void
    {
        $wa = Mockery::mock(WhatsAppSender::class);
        $wa->shouldNotReceive('send');

        $service = new WebsiteAutoReplyService($wa);
        $inquiry = $this->makeInquiry(['source' => BookingInquiry::SOURCE_WHATSAPP]);

        $service->sendIfEligible($inquiry);
        $this->assertTrue(true);
    }

    public function test_spam_status_does_not_trigger_send(): void
    {
        $wa = Mockery::mock(WhatsAppSender::class);
        $wa->shouldNotReceive('send');

        $service = new WebsiteAutoReplyService($wa);
        $inquiry = $this->makeInquiry(['status' => BookingInquiry::STATUS_SPAM]);

        $service->sendIfEligible($inquiry);
        $this->assertTrue(true);
    }

    public function test_empty_phone_does_not_trigger_send(): void
    {
        $wa = Mockery::mock(WhatsAppSender::class);
        $wa->shouldNotReceive('send');
        $wa->shouldNotReceive('normalizePhone');

        $service = new WebsiteAutoReplyService($wa);
        $inquiry = $this->makeInquiry(['customer_phone' => '']);

        $service->sendIfEligible($inquiry);
        $this->assertTrue(true); // reached here = send was not called
    }

    public function test_past_travel_date_does_not_trigger_send(): void
    {
        $wa = Mockery::mock(WhatsAppSender::class);
        $wa->shouldNotReceive('send');
        $wa->shouldNotReceive('normalizePhone');

        $service = new WebsiteAutoReplyService($wa);
        $inquiry = $this->makeInquiry(['travel_date' => now()->subDay()]);

        $service->sendIfEligible($inquiry);
        $this->assertTrue(true);
    }

    public function test_old_inquiry_does_not_trigger_send(): void
    {
        $wa = Mockery::mock(WhatsAppSender::class);
        $wa->shouldNotReceive('send');
        $wa->shouldNotReceive('normalizePhone');

        $service = new WebsiteAutoReplyService($wa);
        $inquiry = $this->makeInquiry(['created_at' => now()->subMinutes(5)]);

        $service->sendIfEligible($inquiry);
        $this->assertTrue(true);
    }
}
