<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\Telegram\TelegramTransportInterface;
use App\DTOs\ResolvedTelegramBot;
use App\Enums\BotEnvironment;
use App\Enums\BotStatus;
use App\Exceptions\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramTransport;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class TelegramTransportTest extends TestCase
{
    private TelegramTransport $transport;
    private ResolvedTelegramBot $bot;

    private const FAKE_TOKEN = '999888777:FAKE-TOKEN-FOR-TESTING-XYZ';

    protected function setUp(): void
    {
        parent::setUp();

        $this->transport = new TelegramTransport();

        $this->bot = new ResolvedTelegramBot(
            botId: 1,
            slug: 'test-bot',
            name: 'Test Bot',
            botUsername: 'test_bot',
            status: BotStatus::Active,
            environment: BotEnvironment::Development,
            token: self::FAKE_TOKEN,
            webhookSecret: 'test-wh-secret',
        );
    }

    // ──────────────────────────────────────────────
    // Interface binding
    // ──────────────────────────────────────────────

    /** @test */
    public function interface_resolves_to_implementation(): void
    {
        $resolved = $this->app->make(TelegramTransportInterface::class);

        $this->assertInstanceOf(TelegramTransport::class, $resolved);
    }

    // ──────────────────────────────────────────────
    // Successful calls
    // ──────────────────────────────────────────────

    /** @test */
    public function call_returns_successful_result(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 42],
            ]),
        ]);

        $result = $this->transport->call($this->bot, 'sendMessage', [
            'chat_id' => 12345,
            'text' => 'Hello',
        ]);

        $this->assertTrue($result->succeeded());
        $this->assertSame(42, $result->result['message_id']);
        $this->assertSame(200, $result->httpStatus);
    }

    /** @test */
    public function get_me_calls_correct_method(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['id' => 999, 'is_bot' => true, 'first_name' => 'Test'],
            ]),
        ]);

        $result = $this->transport->getMe($this->bot);

        $this->assertTrue($result->succeeded());
        $this->assertSame(999, $result->result['id']);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/getMe'));
    }

    /** @test */
    public function send_message_passes_chat_id_and_text(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);

        $this->transport->sendMessage($this->bot, 12345, 'Hello world', [
            'parse_mode' => 'HTML',
        ]);

        Http::assertSent(function ($request) {
            $data = $request->data();

            return $data['chat_id'] === 12345
                && $data['text'] === 'Hello world'
                && $data['parse_mode'] === 'HTML';
        });
    }

    /** @test */
    public function set_webhook_passes_url_and_secret(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => true]),
        ]);

        $this->transport->setWebhook(
            $this->bot,
            'https://example.com/webhook',
            'my-secret',
        );

        Http::assertSent(function ($request) {
            $data = $request->data();

            return str_contains($request->url(), '/setWebhook')
                && $data['url'] === 'https://example.com/webhook'
                && $data['secret_token'] === 'my-secret';
        });
    }

    /** @test */
    public function set_webhook_omits_secret_when_null(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => true]),
        ]);

        $this->transport->setWebhook($this->bot, 'https://example.com/webhook');

        Http::assertSent(function ($request) {
            return ! array_key_exists('secret_token', $request->data());
        });
    }

    /** @test */
    public function delete_webhook_passes_drop_pending(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => true]),
        ]);

        $this->transport->deleteWebhook($this->bot, dropPendingUpdates: true);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/deleteWebhook')
                && $request->data()['drop_pending_updates'] === true;
        });
    }

    /** @test */
    public function get_webhook_info_returns_result(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['url' => 'https://example.com/webhook', 'pending_update_count' => 0],
            ]),
        ]);

        $result = $this->transport->getWebhookInfo($this->bot);

        $this->assertTrue($result->succeeded());
        $this->assertSame('https://example.com/webhook', $result->result['url']);
    }

    // ──────────────────────────────────────────────
    // Error handling
    // ──────────────────────────────────────────────

    /** @test */
    public function returns_error_result_for_400(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => false,
                'error_code' => 400,
                'description' => 'Bad Request: chat not found',
            ], 400),
        ]);

        $result = $this->transport->call($this->bot, 'sendMessage', [
            'chat_id' => 0,
            'text' => 'test',
        ]);

        $this->assertFalse($result->succeeded());
        $this->assertTrue($result->isPermanentError());
        $this->assertSame(400, $result->httpStatus);
        $this->assertSame('Bad Request: chat not found', $result->description);
    }

    /** @test */
    public function returns_rate_limited_result_for_429(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => false,
                'error_code' => 429,
                'description' => 'Too Many Requests: retry after 30',
                'parameters' => ['retry_after' => 30],
            ], 429),
        ]);

        $result = $this->transport->call($this->bot, 'sendMessage', [
            'chat_id' => 1,
            'text' => 'test',
        ]);

        $this->assertTrue($result->isRateLimited());
        // retry_after is in the 'result' field which gets the full body when 'result' key is missing
        // Actually let's check — the body has 'parameters' at top level
        $this->assertFalse($result->succeeded());
    }

    /** @test */
    public function throws_telegram_api_exception_on_connection_failure(): void
    {
        Http::fake([
            'api.telegram.org/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection timed out'),
        ]);

        try {
            $this->transport->call($this->bot, 'sendMessage', [
                'chat_id' => 1,
                'text' => 'test',
            ]);
            $this->fail('Expected TelegramApiException');
        } catch (TelegramApiException $e) {
            $this->assertSame('test-bot', $e->slug);
            $this->assertSame('sendMessage', $e->method);
            $this->assertSame(0, $e->httpStatus);
            $this->assertStringContainsString('Connection timed out', $e->apiError);
        }
    }

    // ──────────────────────────────────────────────
    // SECURITY: Token never appears in logs/exceptions
    // ──────────────────────────────────────────────

    /** @test */
    public function token_does_not_appear_in_exception_message(): void
    {
        Http::fake([
            'api.telegram.org/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'),
        ]);

        try {
            $this->transport->call($this->bot, 'sendMessage', ['chat_id' => 1, 'text' => 'x']);
            $this->fail('Expected TelegramApiException');
        } catch (TelegramApiException $e) {
            $this->assertStringNotContainsString(self::FAKE_TOKEN, $e->getMessage());
            $this->assertStringNotContainsString(self::FAKE_TOKEN, json_encode($e->safeContext()));
            $this->assertStringContainsString('test-bot', $e->getMessage());
        }
    }

    /** @test */
    public function token_does_not_appear_in_error_logs(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => false,
                'error_code' => 500,
                'description' => 'Internal Server Error',
            ], 500),
        ]);

        // Capture all log calls
        $logMessages = [];
        Log::shouldReceive('error')
            ->once()
            ->withArgs(function (string $message, array $context) use (&$logMessages) {
                $logMessages[] = $message . ' ' . json_encode($context);

                return true;
            });

        $this->transport->call($this->bot, 'sendMessage', ['chat_id' => 1, 'text' => 'x']);

        foreach ($logMessages as $log) {
            $this->assertStringNotContainsString(self::FAKE_TOKEN, $log);
            $this->assertStringNotContainsString('api.telegram.org/bot', $log);
            $this->assertStringContainsString('test-bot', $log);
        }
    }

    /** @test */
    public function token_does_not_appear_in_rate_limit_warning(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => false,
                'error_code' => 429,
                'description' => 'Too Many Requests',
                'parameters' => ['retry_after' => 10],
            ], 429),
        ]);

        $logMessages = [];
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context) use (&$logMessages) {
                $logMessages[] = $message . ' ' . json_encode($context);

                return true;
            });

        $this->transport->call($this->bot, 'sendMessage', ['chat_id' => 1, 'text' => 'x']);

        foreach ($logMessages as $log) {
            $this->assertStringNotContainsString(self::FAKE_TOKEN, $log);
        }
    }

    /** @test */
    public function token_does_not_appear_in_network_error_log(): void
    {
        Http::fake([
            'api.telegram.org/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('DNS failure'),
        ]);

        $logMessages = [];
        Log::shouldReceive('error')
            ->once()
            ->withArgs(function (string $message, array $context) use (&$logMessages) {
                $logMessages[] = $message . ' ' . json_encode($context);

                return true;
            });

        try {
            $this->transport->call($this->bot, 'sendMessage', ['chat_id' => 1, 'text' => 'x']);
        } catch (TelegramApiException) {
            // expected
        }

        foreach ($logMessages as $log) {
            $this->assertStringNotContainsString(self::FAKE_TOKEN, $log);
            $this->assertStringNotContainsString('api.telegram.org/bot', $log);
        }
    }

    /** @test */
    public function successful_call_does_not_log(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);

        // Log should NOT receive any calls for successful responses
        Log::shouldReceive('error')->never();
        Log::shouldReceive('warning')->never();
        Log::shouldReceive('info')->never();

        $this->transport->call($this->bot, 'sendMessage', ['chat_id' => 1, 'text' => 'ok']);
    }

    /** @test */
    public function result_object_does_not_contain_token(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 1],
            ]),
        ]);

        $result = $this->transport->call($this->bot, 'sendMessage', [
            'chat_id' => 1,
            'text' => 'test',
        ]);

        $serialized = json_encode($result);

        $this->assertStringNotContainsString(self::FAKE_TOKEN, $serialized);
        $this->assertStringNotContainsString('api.telegram.org/bot', $serialized);
    }

    // ──────────────────────────────────────────────
    // HTTP client configuration
    // ──────────────────────────────────────────────

    /** @test */
    public function requests_are_sent_to_correct_url_structure(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);

        $this->transport->call($this->bot, 'sendMessage', ['chat_id' => 1, 'text' => 'x']);

        Http::assertSent(function ($request) {
            $url = $request->url();

            // URL must contain the token (that's how Telegram API works)
            // but we verify it's sent to the right host
            return str_starts_with($url, 'https://api.telegram.org/bot')
                && str_contains($url, '/sendMessage');
        });
    }
}
