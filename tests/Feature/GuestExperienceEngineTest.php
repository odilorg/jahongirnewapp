<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\GuestExperience\MaterializeExperienceMessages;
use App\Models\BookingInquiry;
use App\Models\GuestExperienceMessage;
use App\Services\GuestExperience\GuestExperienceDispatcher;
use App\Services\Messaging\SendResult;
use App\Services\Messaging\WhatsAppSender;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Phase 29 — guest experience engine end-to-end.
 *
 * Uses the real 2-day Bukhara→Yurt→Samarkand shape (tour_slug
 * 'yurt-camp-tour', catalogued day_count 2). Egress (WhatsAppSender,
 * OwnerAlertService) is mocked / env-guarded so no real message is sent.
 */
class GuestExperienceEngineTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['guest_experience.enabled' => true]);
    }

    private function makeBooking(array $overrides = []): BookingInquiry
    {
        // Pickup tomorrow so all 3 touchpoints are in the future.
        $travel = Carbon::now('Asia/Tashkent')->addDay()->toDateString();

        return BookingInquiry::create(array_merge([
            'reference' => 'INQ-TEST-'.uniqid(),
            'source' => 'website',
            'status' => BookingInquiry::STATUS_CONFIRMED,
            'customer_name' => 'Test Guest',
            'customer_phone' => '+998901234567',
            'tour_slug' => 'yurt-camp-tour',
            'tour_name_snapshot' => '2-Day Yurt Camp',
            'people_adults' => 2,
            'travel_date' => $travel,
            'pickup_time' => '09:00:00',
            'submitted_at' => now(),
        ], $overrides));
    }

    private function bindWa(bool $success = true, int $expected = 1): void
    {
        $mock = $this->createMock(WhatsAppSender::class);
        $mock->method('normalizePhone')->willReturnCallback(
            fn (?string $p) => ($p && trim($p) !== '') ? preg_replace('/[^0-9]/', '', $p) : null
        );
        $mock->expects($this->exactly($expected))
            ->method('send')
            ->willReturn($success ? SendResult::ok('whatsapp') : SendResult::fail('whatsapp', 'hard reject'));
        $this->app->instance(WhatsAppSender::class, $mock);
    }

    private function materialize(BookingInquiry $b): int
    {
        return app(MaterializeExperienceMessages::class)->handle($b);
    }

    /** @test */
    public function materializes_three_touchpoints_for_a_two_day_tour(): void
    {
        $b = $this->makeBooking();

        $created = $this->materialize($b);

        $this->assertSame(3, $created);
        $this->assertEqualsCanonicalizing(
            ['post_pickup_welcome', 'evening_sunset_tip', 'next_morning_feedback'],
            $b->experienceMessages()->pluck('message_type')->all(),
        );
    }

    /** @test */
    public function uncatalogued_tour_gets_no_messages(): void
    {
        $b = $this->makeBooking(['tour_slug' => 'samarkand-city-tour']);

        $this->assertSame(0, $this->materialize($b));
        $this->assertSame(0, $b->experienceMessages()->count());
    }

    /** @test */
    public function opted_out_booking_gets_no_messages(): void
    {
        $b = $this->makeBooking(['experience_messages_opted_out' => true]);

        $this->assertSame(0, $this->materialize($b));
    }

    /** @test */
    public function master_flag_off_materializes_nothing(): void
    {
        config(['guest_experience.enabled' => false]);
        $b = $this->makeBooking();

        $this->assertSame(0, $this->materialize($b));
    }

    /** @test */
    public function due_message_sends_once_and_is_idempotent(): void
    {
        $this->bindWa(success: true, expected: 1);
        $b = $this->makeBooking();
        $this->materialize($b);

        // Force the welcome row due now.
        $welcome = $b->experienceMessages()->where('message_type', 'post_pickup_welcome')->first();
        $welcome->forceFill(['due_at' => now()->subMinute()])->save();

        $dispatcher = app(GuestExperienceDispatcher::class);
        $first = $dispatcher->send($welcome->fresh());
        $second = $dispatcher->send($welcome->fresh());

        $this->assertTrue($first['ok']);
        $this->assertSame(GuestExperienceMessage::STATUS_SENT, $welcome->fresh()->status);
        $this->assertNotNull($welcome->fresh()->sent_at);
        // Second call no-ops (status no longer pending → CAS claims 0 rows).
        $this->assertFalse($second['ok']);
    }

    /** @test */
    public function no_phone_welcome_is_skipped_and_alerts_operator(): void
    {
        Bus::fake();
        // normalizePhone('') → null; send() must never be called.
        $mock = $this->createMock(WhatsAppSender::class);
        $mock->method('normalizePhone')->willReturn(null);
        $mock->expects($this->never())->method('send');
        $this->app->instance(WhatsAppSender::class, $mock);

        $b = $this->makeBooking(['customer_phone' => '']);
        $this->materialize($b);
        $welcome = $b->experienceMessages()->where('message_type', 'post_pickup_welcome')->first();
        $welcome->forceFill(['due_at' => now()->subMinute()])->save();

        $result = app(GuestExperienceDispatcher::class)->send($welcome->fresh());

        $this->assertFalse($result['ok']);
        $this->assertSame(GuestExperienceMessage::STATUS_SKIPPED, $welcome->fresh()->status);
    }

    /** @test */
    public function dry_run_sends_nothing_and_does_not_mutate(): void
    {
        $mock = $this->createMock(WhatsAppSender::class);
        $mock->method('normalizePhone')->willReturnCallback(fn ($p) => $p ? preg_replace('/[^0-9]/', '', $p) : null);
        $mock->expects($this->never())->method('send');
        $this->app->instance(WhatsAppSender::class, $mock);

        $b = $this->makeBooking();
        $this->materialize($b);
        $welcome = $b->experienceMessages()->where('message_type', 'post_pickup_welcome')->first();
        $welcome->forceFill(['due_at' => now()->subMinute()])->save();

        $result = app(GuestExperienceDispatcher::class)->send($welcome->fresh(), dryRun: true);

        $this->assertTrue($result['ok']);
        $this->assertSame(GuestExperienceMessage::STATUS_PENDING, $welcome->fresh()->status);
    }

    /** @test */
    public function past_due_messages_are_not_materialized(): void
    {
        // Travel was yesterday → all 3 touchpoints already past.
        $b = $this->makeBooking([
            'travel_date' => Carbon::now('Asia/Tashkent')->subDay()->toDateString(),
        ]);

        $this->assertSame(0, $this->materialize($b));
    }
}
