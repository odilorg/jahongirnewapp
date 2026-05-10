<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Jobs\SendTelegramNotificationJob;
use App\Services\OwnerAlertService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Verifies OwnerAlertService dispatches via slug (not raw token),
 * and that no token references remain in the service.
 */
class OwnerAlertServiceTest extends TestCase
{
    /** @test */
    public function service_has_no_bot_token_property(): void
    {
        $reflection = new \ReflectionClass(OwnerAlertService::class);

        $propertyNames = array_map(
            fn (\ReflectionProperty $p) => $p->getName(),
            $reflection->getProperties(),
        );

        $this->assertNotContains('botToken', $propertyNames);
        $this->assertNotContains('apiBase', $propertyNames);
        $this->assertContains('ownerChatId', $propertyNames);
    }

    /** @test */
    public function service_source_does_not_reference_token(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(OwnerAlertService::class))->getFileName()
        );

        $this->assertStringNotContainsString('botToken', $source);
        $this->assertStringNotContainsString('apiBase', $source);
        $this->assertStringNotContainsString('OWNER_ALERT_BOT_TOKEN', $source);
        $this->assertStringNotContainsString('api.telegram.org', $source);
    }

    /** @test */
    public function dispatches_job_with_slug_not_token(): void
    {
        Bus::fake([SendTelegramNotificationJob::class]);

        config([
            'services.owner_alert_bot.owner_chat_id' => '12345',
            'services.owner_alert_bot.allow_outbound_in_testing' => true,
        ]);

        $service = new OwnerAlertService;
        $service->sendDailySummary([
            '41097' => ['new_bookings' => 1, 'cancellations' => 0, 'modifications' => 0, 'current_guests' => 5, 'arrivals_tomorrow' => 2, 'departures_tomorrow' => 1, 'revenue_today' => '500', 'currency' => 'USD'],
            '172793' => ['new_bookings' => 0, 'cancellations' => 0, 'modifications' => 0, 'current_guests' => 0, 'arrivals_tomorrow' => 0, 'departures_tomorrow' => 0, 'revenue_today' => '0', 'currency' => 'USD'],
            'unpaid_count' => 0,
        ]);

        Bus::assertDispatched(SendTelegramNotificationJob::class, function ($job) {
            return $job->botSlug === 'owner-alert'
                && $job->method === 'sendMessage'
                && $job->params['chat_id'] === 12345
                && $job->params['parse_mode'] === 'HTML'
                && str_contains($job->params['text'], 'Ежедневный отчёт');
        });
    }

    /** @test */
    public function does_not_dispatch_when_chat_id_is_zero(): void
    {
        Bus::fake([SendTelegramNotificationJob::class]);
        Log::shouldReceive('warning')->once()->withArgs(
            fn (string $msg) => str_contains($msg, 'chat ID not configured')
        );

        config(['services.owner_alert_bot.owner_chat_id' => '0']);

        $service = new OwnerAlertService;
        $service->sendShiftCloseReport('<b>Shift closed</b>');

        Bus::assertNotDispatched(SendTelegramNotificationJob::class);
    }

    /** @test */
    public function serialized_job_does_not_contain_token(): void
    {
        Bus::fake([SendTelegramNotificationJob::class]);

        config([
            'services.owner_alert_bot.owner_chat_id' => '99',
            'services.owner_alert_bot.allow_outbound_in_testing' => true,
        ]);

        $service = new OwnerAlertService;
        $service->sendShiftCloseReport('<b>Test</b>');

        Bus::assertDispatched(SendTelegramNotificationJob::class, function ($job) {
            $serialized = serialize($job);

            $this->assertStringNotContainsString('bot_token', $serialized);
            $this->assertStringNotContainsString('api.telegram.org', $serialized);
            $this->assertStringContainsString('owner-alert', $serialized);

            return true;
        });
    }

    /**
     * Defensive env-guard: in `testing`/`local`, suppress outbound dispatch
     * by default so VPS test runs (which symlink the production .env and
     * therefore inherit the prod owner-bot tokens + real owner chat ID)
     * cannot leak alerts into the operator's Telegram. Tests that
     * deliberately exercise dispatch behavior (the dispatch tests above)
     * opt in by setting `services.owner_alert_bot.allow_outbound_in_testing`.
     *
     * @test
     */
    public function does_not_dispatch_in_testing_environment_by_default(): void
    {
        // Sanity: confirm we're in the testing environment
        // (set by phpunit.xml). If this ever changes, the test below
        // wouldn't actually exercise the guard.
        $this->assertTrue(app()->environment('testing'));

        Bus::fake([SendTelegramNotificationJob::class]);

        config([
            'services.owner_alert_bot.owner_chat_id' => '12345',
            // explicitly DO NOT set allow_outbound_in_testing — verifies
            // the default is "suppress".
        ]);

        $service = new OwnerAlertService;
        $service->sendShiftCloseReport('<b>Test</b>');

        Bus::assertNotDispatched(SendTelegramNotificationJob::class);
    }

    /**
     * Inverse of the above: when the test explicitly opts in via the
     * config flag, the guard lets the dispatch through. Belt-and-suspenders
     * proof that the opt-in mechanism works (so the dispatch tests above
     * aren't relying on accidental behavior).
     *
     * @test
     */
    public function dispatches_in_testing_when_opt_in_flag_is_set(): void
    {
        Bus::fake([SendTelegramNotificationJob::class]);

        config([
            'services.owner_alert_bot.owner_chat_id' => '12345',
            'services.owner_alert_bot.allow_outbound_in_testing' => true,
        ]);

        $service = new OwnerAlertService;
        $service->sendShiftCloseReport('<b>Test</b>');

        Bus::assertDispatched(SendTelegramNotificationJob::class);
    }
}
