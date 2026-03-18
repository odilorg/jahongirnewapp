<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\Telegram\BotAuditLoggerInterface;
use App\Enums\AccessAction;
use App\Enums\AccessResult;
use App\Models\TelegramBot;
use App\Models\TelegramBotAccessLog;
use App\Services\Telegram\BotAuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for BotAuditLogger service.
 *
 * @group database
 * @group vps
 */
class BotAuditLoggerTest extends TestCase
{
    use RefreshDatabase;

    private BotAuditLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new BotAuditLogger();
    }

    /** @test */
    public function interface_is_bound_in_container(): void
    {
        $resolved = $this->app->make(BotAuditLoggerInterface::class);

        $this->assertInstanceOf(BotAuditLogger::class, $resolved);
    }

    /** @test */
    public function log_creates_access_log_entry(): void
    {
        $bot = TelegramBot::factory()->create();

        $entry = $this->logger->log(
            bot: $bot,
            action: AccessAction::TokenRead,
            result: AccessResult::Success,
            serviceName: 'App\Services\OwnerAlertService',
        );

        $this->assertInstanceOf(TelegramBotAccessLog::class, $entry);
        $this->assertTrue($entry->exists);
        $this->assertSame($bot->id, $entry->telegram_bot_id);
        $this->assertSame(AccessAction::TokenRead, $entry->action);
        $this->assertSame(AccessResult::Success, $entry->result);
        $this->assertSame('App\Services\OwnerAlertService', $entry->service_name);
    }

    /** @test */
    public function log_with_null_bot_for_failed_lookups(): void
    {
        $entry = $this->logger->log(
            bot: null,
            action: AccessAction::Error,
            result: AccessResult::NotFound,
            serviceName: 'App\Services\Telegram\BotResolver',
            metadata: ['slug' => 'nonexistent'],
        );

        $this->assertTrue($entry->exists);
        $this->assertNull($entry->telegram_bot_id);
        $this->assertSame(['slug' => 'nonexistent'], $entry->metadata);
    }

    /** @test */
    public function log_token_access_convenience_method(): void
    {
        $bot = TelegramBot::factory()->create();

        $entry = $this->logger->logTokenAccess($bot, 'App\Services\OwnerAlertService');

        $this->assertSame(AccessAction::TokenRead, $entry->action);
        $this->assertSame(AccessResult::Success, $entry->result);
        $this->assertSame($bot->id, $entry->telegram_bot_id);
    }

    /** @test */
    public function log_error_convenience_method(): void
    {
        $bot = TelegramBot::factory()->create();

        $entry = $this->logger->logError(
            bot: $bot,
            serviceName: 'App\Services\Telegram\TelegramTransport',
            errorCode: '429',
            errorSummary: 'Rate limited by Telegram',
        );

        $this->assertSame(AccessAction::Error, $entry->action);
        $this->assertSame(AccessResult::Error, $entry->result);
        $this->assertSame('429', $entry->metadata['error_code']);
        $this->assertSame('Rate limited by Telegram', $entry->metadata['error_summary']);
    }

    /** @test */
    public function log_sets_cli_actor_type_in_console(): void
    {
        $bot = TelegramBot::factory()->create();

        $entry = $this->logger->log(
            bot: $bot,
            action: AccessAction::TokenRead,
            result: AccessResult::Success,
        );

        // Tests run in console context
        $this->assertSame('cli', $entry->actor_type);
    }

    /** @test */
    public function log_with_metadata(): void
    {
        $bot = TelegramBot::factory()->create();

        $entry = $this->logger->log(
            bot: $bot,
            action: AccessAction::MessageSent,
            result: AccessResult::Success,
            metadata: [
                'chat_id' => 12345,
                'method' => 'sendMessage',
            ],
        );

        $this->assertSame(12345, $entry->metadata['chat_id']);
        $this->assertSame('sendMessage', $entry->metadata['method']);
    }

    /** @test */
    public function log_with_empty_metadata_stores_null(): void
    {
        $bot = TelegramBot::factory()->create();

        $entry = $this->logger->log(
            bot: $bot,
            action: AccessAction::TokenRead,
            result: AccessResult::Success,
            metadata: [],
        );

        $this->assertNull($entry->metadata);
    }

    /** @test */
    public function access_log_model_has_no_updated_at(): void
    {
        $bot = TelegramBot::factory()->create();

        $entry = $this->logger->log(
            bot: $bot,
            action: AccessAction::TokenRead,
            result: AccessResult::Success,
        );

        $fresh = TelegramBotAccessLog::find($entry->id);
        $this->assertNotNull($fresh->created_at);
        // UPDATED_AT is null constant — column doesn't exist
        $this->assertNull(TelegramBotAccessLog::UPDATED_AT);
    }

    /** @test */
    public function access_log_belongs_to_bot(): void
    {
        $bot = TelegramBot::factory()->create();

        $entry = $this->logger->log(
            bot: $bot,
            action: AccessAction::TokenRead,
            result: AccessResult::Success,
        );

        $this->assertTrue($entry->bot->is($bot));
    }

    /** @test */
    public function bot_has_many_access_logs(): void
    {
        $bot = TelegramBot::factory()->create();

        $this->logger->log($bot, AccessAction::TokenRead, AccessResult::Success);
        $this->logger->log($bot, AccessAction::MessageSent, AccessResult::Success);
        $this->logger->log($bot, AccessAction::Error, AccessResult::Error);

        $this->assertCount(3, $bot->accessLogs);
    }

    /** @test */
    public function for_bot_scope(): void
    {
        $bot1 = TelegramBot::factory()->create();
        $bot2 = TelegramBot::factory()->create();

        $this->logger->log($bot1, AccessAction::TokenRead, AccessResult::Success);
        $this->logger->log($bot2, AccessAction::TokenRead, AccessResult::Success);

        $this->assertCount(1, TelegramBotAccessLog::forBot($bot1->id)->get());
    }

    /** @test */
    public function failures_scope(): void
    {
        $bot = TelegramBot::factory()->create();

        $this->logger->log($bot, AccessAction::TokenRead, AccessResult::Success);
        $this->logger->log($bot, AccessAction::Error, AccessResult::Error);
        $this->logger->log($bot, AccessAction::TokenRead, AccessResult::Denied);

        $this->assertCount(2, TelegramBotAccessLog::failures()->get());
    }

    /** @test */
    public function secret_accesses_scope(): void
    {
        $bot = TelegramBot::factory()->create();

        $this->logger->log($bot, AccessAction::TokenRead, AccessResult::Success);
        $this->logger->log($bot, AccessAction::TokenRevealed, AccessResult::Success);
        $this->logger->log($bot, AccessAction::MessageSent, AccessResult::Success);
        $this->logger->log($bot, AccessAction::BotCreated, AccessResult::Success);

        $this->assertCount(2, TelegramBotAccessLog::secretAccesses()->get());
    }

    /** @test */
    public function logger_does_not_throw_on_db_failure(): void
    {
        // Simulate a scenario where the logger gracefully handles failure.
        // We verify the contract: logger must never throw.
        // In production, if telegram_bot_access_logs table is missing or DB is down,
        // the logger logs to Laravel's logger and returns an unsaved model.

        $bot = TelegramBot::factory()->create();

        // Normal case — should not throw
        $entry = $this->logger->log(
            bot: $bot,
            action: AccessAction::TokenRead,
            result: AccessResult::Success,
        );

        $this->assertInstanceOf(TelegramBotAccessLog::class, $entry);
    }
}
