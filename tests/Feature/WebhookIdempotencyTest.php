<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessTelegramUpdateJob;
use App\Models\IncomingWebhook;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tests that handleWebhook is idempotent against duplicate Telegram update_ids.
 *
 * Telegram retries the same update_id when it receives a non-2xx response.
 * The fix uses firstOrCreate keyed by event_id so that:
 *   - first delivery  → INSERT + dispatch job
 *   - duplicate       → SELECT (no INSERT) + no dispatch + HTTP 200
 *
 * Both HousekeepingBotController and KitchenBotController share the same
 * pattern and are tested here.
 *
 * ## Database strategy
 *
 * The project's full migration chain has known idempotency issues across
 * many unrelated tables. Rather than running migrate:fresh (which fails),
 * we run only the two migrations that create incoming_webhooks, and tear
 * them down after each test. This keeps the tests fast and self-contained.
 *
 * ## Middleware note
 *
 * VerifyTelegramWebhook passes through in migration mode when no webhook
 * secret is configured (the default in test env), so these tests do not
 * need the X-Telegram-Bot-Api-Secret-Token header.
 */
class WebhookIdempotencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Beds24BookingService reads this at construction time. It's never
        // called inside handleWebhook, but the controller's DI chain needs
        // the constructor to succeed.
        config(['services.beds24.api_v2_refresh_token' => 'test-placeholder']);

        // Create only the tables this test needs, bypassing the full (broken)
        // migration chain. Both migrations are idempotent — no-ops if already run.
        Artisan::call('migrate', [
            '--path'   => 'database/migrations/2026_03_17_300000_create_incoming_webhooks_table.php',
            '--force'  => true,
        ]);
        Artisan::call('migrate', [
            '--path'   => 'database/migrations/2026_03_18_100000_add_unique_event_id_to_incoming_webhooks.php',
            '--force'  => true,
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('incoming_webhooks');
        // Remove the migration records so the next test re-runs the migrations cleanly.
        \Illuminate\Support\Facades\DB::table('migrations')
            ->whereIn('migration', [
                '2026_03_17_300000_create_incoming_webhooks_table',
                '2026_03_18_100000_add_unique_event_id_to_incoming_webhooks',
            ])
            ->delete();

        parent::tearDown();
    }

    /** @return array<string, array{string, string}> */
    public static function botProvider(): array
    {
        return [
            'housekeeping bot' => [
                '/api/telegram/housekeeping/webhook',
                'telegram:housekeeping',
            ],
            'kitchen bot' => [
                '/api/telegram/kitchen/webhook',
                'telegram:kitchen',
            ],
        ];
    }

    private function telegramPayload(int $updateId): array
    {
        return [
            'update_id' => $updateId,
            'message'   => [
                'message_id' => 1,
                'from'       => ['id' => 999, 'first_name' => 'Test'],
                'chat'       => ['id' => 999, 'type' => 'private'],
                'text'       => '/start',
                'date'       => time(),
            ],
        ];
    }

    // ── First delivery ────────────────────────────────────────────────────────

    /**
     * @dataProvider botProvider
     */
    public function test_first_delivery_creates_one_row(string $url, string $source): void
    {
        Queue::fake();

        $this->postJson($url, $this->telegramPayload(11111))->assertStatus(200);

        $this->assertDatabaseCount('incoming_webhooks', 1);
        $this->assertDatabaseHas('incoming_webhooks', [
            'source' => $source,
            'status' => IncomingWebhook::STATUS_PENDING,
        ]);
    }

    /**
     * @dataProvider botProvider
     */
    public function test_first_delivery_dispatches_job(string $url): void
    {
        Queue::fake();

        $this->postJson($url, $this->telegramPayload(22222))->assertStatus(200);

        Queue::assertPushed(ProcessTelegramUpdateJob::class, 1);
    }

    /**
     * @dataProvider botProvider
     */
    public function test_first_delivery_returns_200(string $url): void
    {
        Queue::fake();

        $this->postJson($url, $this->telegramPayload(33333))->assertStatus(200);
    }

    // ── Duplicate delivery ────────────────────────────────────────────────────

    /**
     * @dataProvider botProvider
     */
    public function test_duplicate_delivery_returns_200(string $url): void
    {
        Queue::fake();

        $this->postJson($url, $this->telegramPayload(44444))->assertStatus(200);
        $this->postJson($url, $this->telegramPayload(44444))->assertStatus(200);
    }

    /**
     * @dataProvider botProvider
     */
    public function test_duplicate_delivery_does_not_create_second_row(string $url): void
    {
        Queue::fake();

        $this->postJson($url, $this->telegramPayload(55555));
        $this->postJson($url, $this->telegramPayload(55555));

        $this->assertDatabaseCount('incoming_webhooks', 1);
    }

    /**
     * @dataProvider botProvider
     */
    public function test_duplicate_delivery_does_not_dispatch_second_job(string $url): void
    {
        Queue::fake();

        $this->postJson($url, $this->telegramPayload(66666));
        $this->postJson($url, $this->telegramPayload(66666));

        // Job must be dispatched exactly once regardless of how many retries arrive.
        Queue::assertPushed(ProcessTelegramUpdateJob::class, 1);
    }

    /**
     * @dataProvider botProvider
     */
    public function test_three_retries_still_dispatch_once(string $url): void
    {
        Queue::fake();

        $payload = $this->telegramPayload(77777);
        $this->postJson($url, $payload);
        $this->postJson($url, $payload);
        $this->postJson($url, $payload);

        $this->assertDatabaseCount('incoming_webhooks', 1);
        Queue::assertPushed(ProcessTelegramUpdateJob::class, 1);
    }

    // ── Different update_ids are independent ──────────────────────────────────

    /**
     * @dataProvider botProvider
     */
    public function test_different_update_ids_each_create_their_own_row(string $url): void
    {
        Queue::fake();

        $this->postJson($url, $this->telegramPayload(88881))->assertStatus(200);
        $this->postJson($url, $this->telegramPayload(88882))->assertStatus(200);
        $this->postJson($url, $this->telegramPayload(88883))->assertStatus(200);

        $this->assertDatabaseCount('incoming_webhooks', 3);
        Queue::assertPushed(ProcessTelegramUpdateJob::class, 3);
    }

    // ── Cross-bot independence ────────────────────────────────────────────────

    /**
     * The same Telegram update_id integer from two different bots must produce
     * two separate rows because event_id is namespaced by bot
     * ("housekeeping:12345" vs "kitchen:12345").
     */
    public function test_same_update_id_from_different_bots_creates_two_rows(): void
    {
        Queue::fake();

        $this->postJson('/api/telegram/housekeeping/webhook', $this->telegramPayload(99999))
            ->assertStatus(200);

        $this->postJson('/api/telegram/kitchen/webhook', $this->telegramPayload(99999))
            ->assertStatus(200);

        $this->assertDatabaseCount('incoming_webhooks', 2);
        $this->assertDatabaseHas('incoming_webhooks', ['event_id' => 'housekeeping:99999']);
        $this->assertDatabaseHas('incoming_webhooks', ['event_id' => 'kitchen:99999']);
        Queue::assertPushed(ProcessTelegramUpdateJob::class, 2);
    }
}
