<?php

declare(strict_types=1);

namespace Tests\Feature\BookingBot;

use App\Jobs\ProcessBookingMessage;
use App\Models\User;
use App\Services\BookingBot\DeepSeekIntentParser;
use App\Services\BookingBot\IntentParseException;
use App\Services\BookingIntentParser;
use App\Services\StaffAuthorizationService;
use App\Services\TelegramBotService;
use App\Services\TelegramKeyboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * Feature test — when the parser fails (e.g. DeepSeek timeout), the
 * operator sees a friendly menu-hint reply. No raw cURL / URL leaks.
 */
final class ProcessBookingMessageParserFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_parser_failure_sends_menu_hint_not_raw_curl_error(): void
    {
        // Stub DeepSeek to simulate a network timeout wrapped in our
        // sanitized exception (as the real adapter does).
        $remote = Mockery::mock(DeepSeekIntentParser::class);
        $remote->shouldReceive('parse')
            ->once()
            ->andThrow(new IntentParseException('Intent parser unavailable: cURL error 28'));
        $this->app->instance(DeepSeekIntentParser::class, $remote);

        // Re-resolve the coordinator so it picks up the stubbed remote.
        $this->app->forgetInstance(BookingIntentParser::class);

        // Authorize a staff member.
        $staff = User::factory()->create(['name' => 'Op']);
        $auth = Mockery::mock(StaffAuthorizationService::class);
        $auth->shouldReceive('verifyTelegramUser')->andReturn($staff);
        $this->app->instance(StaffAuthorizationService::class, $auth);

        $capturedReply = null;
        $telegram = Mockery::mock(TelegramBotService::class);
        $telegram->shouldReceive('sendMessage')
            ->once()
            ->andReturnUsing(function (int|string $chatId, string $text) use (&$capturedReply): array {
                $capturedReply = $text;
                return ['ok' => true];
            });
        $this->app->instance(TelegramBotService::class, $telegram);

        $keyboard = Mockery::mock(TelegramKeyboardService::class);
        // Not used on the error path; allow anything.
        $keyboard->shouldReceive('formatForApi')->zeroOrMoreTimes();
        $keyboard->shouldReceive('getMainMenu')->zeroOrMoreTimes();
        $keyboard->shouldReceive('getBackButton')->zeroOrMoreTimes();
        $this->app->instance(TelegramKeyboardService::class, $keyboard);

        // Capture structured log context so we can assert no PII leaks.
        /** @var array<int, array<string, mixed>> $logCalls */
        $logCalls = [];
        Log::swap(new class($logCalls) {
            public function __construct(private array &$calls) {}
            public function __call(string $method, array $args): void
            {
                $this->calls[] = ['level' => $method, 'message' => $args[0] ?? null, 'context' => $args[1] ?? []];
            }
        });

        // Real operator input carrying realistic PII (guest + phone).
        $piiMessage = 'book room 12 under John Walker jan 2-3 tel +1234567890 email alice@example.com';

        $update = [
            'update_id' => 1,
            'message' => [
                'message_id' => 1,
                'chat'       => ['id' => 12345],
                'from'       => ['id' => 1, 'username' => 'op'],
                'text'       => $piiMessage,
                'date'       => time(),
            ],
        ];

        (new ProcessBookingMessage($update))->handle(
            $this->app->make(StaffAuthorizationService::class),
            $this->app->make(BookingIntentParser::class),
            $this->app->make(TelegramBotService::class),
            $this->app->make(TelegramKeyboardService::class),
        );

        $this->assertIsString($capturedReply);
        // Must contain a menu hint.
        $this->assertStringContainsString("couldn't parse", $capturedReply);
        $this->assertStringContainsString('bookings today', $capturedReply);
        $this->assertStringContainsString('/menu', $capturedReply);
        // Must NOT leak raw transport strings.
        $this->assertStringNotContainsString('cURL', $capturedReply);
        $this->assertStringNotContainsString('http', $capturedReply);
        $this->assertStringNotContainsString('deepseek', strtolower($capturedReply));

        // Log hygiene: no log context may contain the full PII payload
        // (phone, email) by default — only length + 40-char prefix.
        $this->assertNotEmpty($logCalls, 'parse failure must be logged');
        foreach ($logCalls as $call) {
            $encoded = json_encode($call['context'] ?? []);
            $this->assertIsString($encoded);
            $this->assertStringNotContainsString('+1234567890', $encoded,
                'phone number leaked into log context');
            $this->assertStringNotContainsString('alice@example.com', $encoded,
                'email leaked into log context');
        }
    }
}
