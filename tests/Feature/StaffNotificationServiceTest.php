<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\Telegram\BotResolverInterface;
use App\Contracts\Telegram\TelegramTransportInterface;
use App\DTOs\ResolvedTelegramBot;
use App\DTOs\TelegramApiResult;
use App\Enums\BotEnvironment;
use App\Enums\BotStatus;
use App\Models\Booking;
use App\Models\Driver;
use App\Models\Guide;
use App\Services\StaffNotificationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Tests for StaffNotificationService.
 *
 * Covers:
 *  - driver/guide with telegram_chat_id receives a message
 *  - driver/guide without telegram_chat_id → sendMessage is never called
 *  - transport failure does NOT throw (assignment must not break)
 *  - resolver failure does NOT throw
 *  - message contains key booking fields
 */
class StaffNotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('bookings')->delete();
        DB::table('drivers')->delete();
        DB::table('guides')->delete();
    }

    protected function tearDown(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        parent::tearDown();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeBot(): ResolvedTelegramBot
    {
        return new ResolvedTelegramBot(
            botId: 1,
            slug: 'driver-guide',
            name: 'Driver Guide Bot',
            botUsername: 'driver_guide_bot',
            status: BotStatus::Active,
            environment: BotEnvironment::Production,
            token: 'fake-token',
        );
    }

    private function okResult(): TelegramApiResult
    {
        return new TelegramApiResult(ok: true, result: [], httpStatus: 200);
    }

    private function failResult(): TelegramApiResult
    {
        return new TelegramApiResult(ok: false, result: null, httpStatus: 400);
    }

    private function makeService(
        BotResolverInterface $resolver,
        TelegramTransportInterface $transport,
    ): StaffNotificationService {
        return new StaffNotificationService($resolver, $transport);
    }

    private function createDriver(array $overrides = []): Driver
    {
        return Driver::create(array_merge([
            'first_name'        => 'Ali',
            'last_name'         => 'Valiyev',
            'phone01'           => '+998901111111',
            'email'             => 'ali@example.com',
            'fuel_type'         => 'Petrol',
            'is_active'         => true,
            'telegram_chat_id'  => null,
        ], $overrides));
    }

    private function createGuide(array $overrides = []): Guide
    {
        return Guide::create(array_merge([
            'first_name'        => 'Bobur',
            'last_name'         => 'Karimov',
            'phone01'           => '+998902222222',
            'email'             => 'bobur@example.com',
            'lang_spoken'       => ['EN'],
            'is_active'         => true,
            'telegram_chat_id'  => null,
        ], $overrides));
    }

    private function makeBooking(): Booking
    {
        $id = DB::table('bookings')->insertGetId([
            'driver_id'               => null,
            'guide_id'                => null,
            'tour_id'                 => 1,
            'guest_id'                => null,
            'grand_total'             => 100,
            'amount'                  => 100,
            'payment_method'          => 'cash',
            'payment_status'          => 'unpaid',
            'group_name'              => 'Test Group',
            'pickup_location'         => 'Registan Square',
            'dropoff_location'        => 'Hotel',
            'booking_status'          => 'confirmed',
            'booking_source'          => 'test',
            'booking_start_date_time' => Carbon::today()->format('Y-m-d'),
            'booking_number'          => 'JT-2026-001',
            'created_at'              => now(),
            'updated_at'              => now(),
        ]);

        return Booking::find($id);
    }

    // ── Driver notifications ──────────────────────────────────────────────────

    /** @test */
    public function driver_with_telegram_id_receives_assignment_notification(): void
    {
        $driver  = $this->createDriver(['telegram_chat_id' => 999888]);
        $booking = $this->makeBooking();

        $resolver  = $this->createMock(BotResolverInterface::class);
        $transport = $this->createMock(TelegramTransportInterface::class);

        $resolver->expects($this->once())
            ->method('resolve')
            ->with('driver-guide')
            ->willReturn($this->makeBot());

        $transport->expects($this->once())
            ->method('sendMessage')
            ->with($this->anything(), 999888, $this->stringContains($booking->booking_number))
            ->willReturn($this->okResult());

        $this->makeService($resolver, $transport)->notifyDriverAssigned($driver, $booking);
    }

    /** @test */
    public function driver_without_telegram_id_does_not_send(): void
    {
        $driver  = $this->createDriver(['telegram_chat_id' => null]);
        $booking = $this->makeBooking();

        $transport = $this->createMock(TelegramTransportInterface::class);
        $transport->expects($this->never())->method('sendMessage');

        $resolver = $this->createMock(BotResolverInterface::class);
        $resolver->expects($this->never())->method('resolve');

        $this->makeService($resolver, $transport)->notifyDriverAssigned($driver, $booking);
    }

    /** @test */
    public function driver_notification_failure_does_not_throw(): void
    {
        $driver  = $this->createDriver(['telegram_chat_id' => 12345]);
        $booking = $this->makeBooking();

        $resolver  = $this->createMock(BotResolverInterface::class);
        $transport = $this->createMock(TelegramTransportInterface::class);

        $resolver->method('resolve')->willThrowException(new \RuntimeException('Bot not found'));

        // Must not throw — assignment must proceed
        $this->makeService($resolver, $transport)->notifyDriverAssigned($driver, $booking);

        // If we get here without an exception, the test passes
        $this->assertTrue(true);
    }

    /** @test */
    public function driver_notification_message_contains_booking_reference_and_pickup(): void
    {
        $driver  = $this->createDriver(['telegram_chat_id' => 999888]);
        $booking = $this->makeBooking();

        $resolver  = $this->createMock(BotResolverInterface::class);
        $transport = $this->createMock(TelegramTransportInterface::class);

        $resolver->method('resolve')->willReturn($this->makeBot());

        $capturedText = null;
        $transport->method('sendMessage')
            ->willReturnCallback(function ($bot, $chatId, $text) use (&$capturedText) {
                $capturedText = $text;
                return new TelegramApiResult(ok: true, result: [], httpStatus: 200);
            });

        $this->makeService($resolver, $transport)->notifyDriverAssigned($driver, $booking);

        $this->assertStringContainsString('JT-2026-001', $capturedText);
        $this->assertStringContainsString('Registan Square', $capturedText);
    }

    // ── Guide notifications ───────────────────────────────────────────────────

    /** @test */
    public function guide_with_telegram_id_receives_assignment_notification(): void
    {
        $guide   = $this->createGuide(['telegram_chat_id' => 777666]);
        $booking = $this->makeBooking();

        $resolver  = $this->createMock(BotResolverInterface::class);
        $transport = $this->createMock(TelegramTransportInterface::class);

        $resolver->expects($this->once())
            ->method('resolve')
            ->with('driver-guide')
            ->willReturn($this->makeBot());

        $transport->expects($this->once())
            ->method('sendMessage')
            ->with($this->anything(), 777666, $this->anything())
            ->willReturn($this->okResult());

        $this->makeService($resolver, $transport)->notifyGuideAssigned($guide, $booking);
    }

    /** @test */
    public function guide_without_telegram_id_does_not_send(): void
    {
        $guide   = $this->createGuide(['telegram_chat_id' => null]);
        $booking = $this->makeBooking();

        $transport = $this->createMock(TelegramTransportInterface::class);
        $transport->expects($this->never())->method('sendMessage');

        $resolver = $this->createMock(BotResolverInterface::class);
        $resolver->expects($this->never())->method('resolve');

        $this->makeService($resolver, $transport)->notifyGuideAssigned($guide, $booking);
    }

    /** @test */
    public function guide_notification_failure_does_not_throw(): void
    {
        $guide   = $this->createGuide(['telegram_chat_id' => 12345]);
        $booking = $this->makeBooking();

        $resolver  = $this->createMock(BotResolverInterface::class);
        $transport = $this->createMock(TelegramTransportInterface::class);

        $resolver->method('resolve')->willThrowException(new \RuntimeException('Bot not found'));

        $this->makeService($resolver, $transport)->notifyGuideAssigned($guide, $booking);

        $this->assertTrue(true);
    }

    /** @test */
    public function non_ok_transport_response_is_logged_but_does_not_throw(): void
    {
        $driver  = $this->createDriver(['telegram_chat_id' => 11111]);
        $booking = $this->makeBooking();

        $resolver  = $this->createMock(BotResolverInterface::class);
        $transport = $this->createMock(TelegramTransportInterface::class);

        $resolver->method('resolve')->willReturn($this->makeBot());
        $transport->method('sendMessage')->willReturn($this->failResult());

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn ($msg) => str_contains($msg, 'StaffNotificationService'));

        $this->makeService($resolver, $transport)->notifyDriverAssigned($driver, $booking);
    }
}
