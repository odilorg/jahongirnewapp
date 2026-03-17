<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CashierBotSecurityTest extends TestCase
{
    use RefreshDatabase;

    // ── Webhook verification ────────────────────────────

    public function test_webhook_rejects_request_without_secret_header(): void
    {
        config(['services.cashier_bot.webhook_secret' => 'test-secret-123']);

        $response = $this->postJson('/api/telegram/cashier/webhook', [
            'message' => ['chat' => ['id' => 123], 'text' => 'hello'],
        ]);

        $response->assertStatus(403);
    }

    public function test_webhook_rejects_request_with_wrong_secret(): void
    {
        config(['services.cashier_bot.webhook_secret' => 'correct-secret']);

        $response = $this->postJson('/api/telegram/cashier/webhook', [
            'message' => ['chat' => ['id' => 123], 'text' => 'hello'],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => 'wrong-secret',
        ]);

        $response->assertStatus(403);
    }

    public function test_webhook_rejects_when_secret_not_configured(): void
    {
        config(['services.cashier_bot.webhook_secret' => '']);

        $response = $this->postJson('/api/telegram/cashier/webhook', [
            'message' => ['chat' => ['id' => 123], 'text' => 'hello'],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => 'anything',
        ]);

        $response->assertStatus(403);
    }

    public function test_webhook_accepts_request_with_valid_secret(): void
    {
        config(['services.cashier_bot.webhook_secret' => 'valid-secret']);
        config(['services.cashier_bot.token' => 'fake-bot-token']);

        $response = $this->postJson('/api/telegram/cashier/webhook', [
            'message' => ['chat' => ['id' => 123], 'text' => 'hello'],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => 'valid-secret',
        ]);

        // Should not be 403 — the request passes auth.
        // May return 200 (OK response from controller) even if no session exists.
        $response->assertStatus(200);
    }

    // ── Callback idempotency ────────────────────────────

    public function test_first_callback_is_recorded(): void
    {
        $callbackId = 'cb_test_' . uniqid();

        // Insert directly to simulate what the controller does
        DB::table('telegram_processed_callbacks')->insert([
            'callback_query_id' => $callbackId,
            'chat_id'           => 12345,
            'action'            => 'confirm_payment',
            'result'            => 'processed',
            'processed_at'      => now(),
        ]);

        $this->assertDatabaseHas('telegram_processed_callbacks', [
            'callback_query_id' => $callbackId,
            'action'            => 'confirm_payment',
        ]);
    }

    public function test_duplicate_callback_is_rejected_by_unique_constraint(): void
    {
        $callbackId = 'cb_dup_' . uniqid();

        DB::table('telegram_processed_callbacks')->insert([
            'callback_query_id' => $callbackId,
            'chat_id'           => 12345,
            'action'            => 'confirm_payment',
            'result'            => 'processed',
            'processed_at'      => now(),
        ]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        DB::table('telegram_processed_callbacks')->insert([
            'callback_query_id' => $callbackId,
            'chat_id'           => 12345,
            'action'            => 'confirm_payment',
            'result'            => 'processed',
            'processed_at'      => now(),
        ]);
    }

    public function test_different_callbacks_are_both_accepted(): void
    {
        DB::table('telegram_processed_callbacks')->insert([
            'callback_query_id' => 'cb_a_' . uniqid(),
            'chat_id'           => 12345,
            'action'            => 'confirm_payment',
            'result'            => 'processed',
            'processed_at'      => now(),
        ]);

        DB::table('telegram_processed_callbacks')->insert([
            'callback_query_id' => 'cb_b_' . uniqid(),
            'chat_id'           => 12345,
            'action'            => 'confirm_expense',
            'result'            => 'processed',
            'processed_at'      => now(),
        ]);

        $this->assertEquals(2, DB::table('telegram_processed_callbacks')->count());
    }
}
