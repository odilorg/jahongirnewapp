<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\BookingInquiry;
use App\Models\Driver;
use App\Models\Guide;
use App\Services\DriverDispatchNotifier;
use App\Services\TgDirectClient;
use Mockery;
use Tests\TestCase;

/**
 * Tests that DriverDispatchNotifier sends to the right destination:
 * telegram_chat_id first, phone01 fallback, error when both empty.
 */
class DispatchNotifierDestinationTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeInquiry(array $driverAttrs = [], array $guideAttrs = []): BookingInquiry
    {
        $inquiry = new BookingInquiry();
        $inquiry->reference = 'INQ-TEST-000001';
        $inquiry->tour_name_snapshot = 'Test Tour';
        $inquiry->customer_name = 'Test Guest';
        $inquiry->customer_phone = '+1234567890';
        $inquiry->people_adults = 2;
        $inquiry->people_children = 0;
        $inquiry->travel_date = now()->addDay();
        $inquiry->pickup_time = '09:00';
        $inquiry->pickup_point = 'Test Hotel';
        $inquiry->dropoff_point = 'Test City';
        $inquiry->operational_notes = '';

        if (! empty($driverAttrs)) {
            $driver = new Driver();
            $driver->forceFill(array_merge([
                'id' => 1,
                'first_name' => 'Test',
                'last_name' => 'Driver',
                'phone01' => '+998901111111',
                'telegram_chat_id' => null,
            ], $driverAttrs));
            $inquiry->setRelation('driver', $driver);
            $inquiry->driver_id = $driver->id;
        }

        if (! empty($guideAttrs)) {
            $guide = new Guide();
            $guide->forceFill(array_merge([
                'id' => 1,
                'first_name' => 'Test',
                'last_name' => 'Guide',
                'phone01' => '+998902222222',
                'telegram_chat_id' => null,
            ], $guideAttrs));
            $inquiry->setRelation('guide', $guide);
            $inquiry->guide_id = $guide->id;
        }

        return $inquiry;
    }

    public function test_sends_to_telegram_username_when_available(): void
    {
        $tgClient = Mockery::mock(TgDirectClient::class);
        $tgClient->shouldReceive('send')
            ->once()
            ->withArgs(fn ($to) => $to === '@testdriver')
            ->andReturn(['ok' => true, 'msg_id' => 1]);

        $notifier = new DriverDispatchNotifier($tgClient);
        $inquiry = $this->makeInquiry(['telegram_chat_id' => '@testdriver', 'phone01' => '+998901111111']);

        $result = $notifier->dispatchSupplier($inquiry, 'driver');

        $this->assertTrue($result['ok']);
    }

    public function test_falls_back_to_phone_when_no_telegram(): void
    {
        $tgClient = Mockery::mock(TgDirectClient::class);
        $tgClient->shouldReceive('send')
            ->once()
            ->withArgs(fn ($to) => $to === '+998901111111')
            ->andReturn(['ok' => true, 'msg_id' => 2]);

        $notifier = new DriverDispatchNotifier($tgClient);
        $inquiry = $this->makeInquiry(['telegram_chat_id' => null, 'phone01' => '+998901111111']);

        $result = $notifier->dispatchSupplier($inquiry, 'driver');

        $this->assertTrue($result['ok']);
    }

    public function test_returns_error_when_no_telegram_and_no_phone(): void
    {
        $tgClient = Mockery::mock(TgDirectClient::class);
        $tgClient->shouldNotReceive('send');

        $notifier = new DriverDispatchNotifier($tgClient);
        $inquiry = $this->makeInquiry(['telegram_chat_id' => null, 'phone01' => '']);

        $result = $notifier->dispatchSupplier($inquiry, 'driver');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('no_telegram_or_phone', $result['reason']);
    }

    public function test_returns_error_when_no_driver_assigned(): void
    {
        $tgClient = Mockery::mock(TgDirectClient::class);
        $tgClient->shouldNotReceive('send');

        $notifier = new DriverDispatchNotifier($tgClient);
        $inquiry = new BookingInquiry();
        $inquiry->setRelation('driver', null);
        $inquiry->setRelation('guide', null);

        $result = $notifier->dispatchSupplier($inquiry, 'driver');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('no_driver', $result['reason']);
    }

    public function test_driver_message_includes_guide_info(): void
    {
        $sentMessage = null;
        $tgClient = Mockery::mock(TgDirectClient::class);
        $tgClient->shouldReceive('send')
            ->once()
            ->withArgs(function ($to, $msg) use (&$sentMessage) {
                $sentMessage = $msg;
                return true;
            })
            ->andReturn(['ok' => true, 'msg_id' => 3]);

        $notifier = new DriverDispatchNotifier($tgClient);
        $inquiry = $this->makeInquiry(
            ['telegram_chat_id' => '@driver1', 'first_name' => 'Muhammad', 'last_name' => 'K'],
            ['first_name' => 'Mehroj', 'last_name' => 'G', 'phone01' => '+998507774207'],
        );

        $notifier->dispatchSupplier($inquiry, 'driver');

        $this->assertNotNull($sentMessage);
        $this->assertStringContainsString('Mehroj', $sentMessage);
        $this->assertStringContainsString('+998507774207', $sentMessage);
    }

    public function test_guide_message_includes_driver_info(): void
    {
        $sentMessage = null;
        $tgClient = Mockery::mock(TgDirectClient::class);
        $tgClient->shouldReceive('send')
            ->once()
            ->withArgs(function ($to, $msg) use (&$sentMessage) {
                $sentMessage = $msg;
                return true;
            })
            ->andReturn(['ok' => true, 'msg_id' => 4]);

        $notifier = new DriverDispatchNotifier($tgClient);
        $inquiry = $this->makeInquiry(
            ['first_name' => 'Muhammad', 'last_name' => 'K', 'phone01' => '+998901234567'],
            ['telegram_chat_id' => '@guide1', 'first_name' => 'Mehroj', 'last_name' => 'G'],
        );

        $notifier->dispatchSupplier($inquiry, 'guide');

        $this->assertNotNull($sentMessage);
        $this->assertStringContainsString('Muhammad', $sentMessage);
        $this->assertStringContainsString('+998901234567', $sentMessage);
    }
}
