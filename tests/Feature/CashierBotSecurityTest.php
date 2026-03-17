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

        $response->assertStatus(200);
    }

    // ── Callback idempotency lifecycle ──────────────────

    public function test_first_claim_succeeds_with_processing_status(): void
    {
        $callbackId = 'cb_first_' . uniqid();

        DB::table('telegram_processed_callbacks')->insert([
            'callback_query_id' => $callbackId,
            'chat_id'           => 12345,
            'action'            => 'confirm_payment',
            'status'            => 'processing',
            'claimed_at'        => now(),
        ]);

        $this->assertDatabaseHas('telegram_processed_callbacks', [
            'callback_query_id' => $callbackId,
            'status'            => 'processing',
        ]);
    }

    public function test_duplicate_claim_is_rejected_by_unique_constraint(): void
    {
        $callbackId = 'cb_dup_' . uniqid();

        DB::table('telegram_processed_callbacks')->insert([
            'callback_query_id' => $callbackId,
            'chat_id'           => 12345,
            'action'            => 'confirm_payment',
            'status'            => 'processing',
            'claimed_at'        => now(),
        ]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        DB::table('telegram_processed_callbacks')->insert([
            'callback_query_id' => $callbackId,
            'chat_id'           => 12345,
            'action'            => 'confirm_payment',
            'status'            => 'processing',
            'claimed_at'        => now(),
        ]);
    }

    public function test_succeeded_callback_blocks_retry(): void
    {
        $callbackId = 'cb_success_' . uniqid();

        DB::table('telegram_processed_callbacks')->insert([
            'callback_query_id' => $callbackId,
            'chat_id'           => 12345,
            'action'            => 'confirm_payment',
            'status'            => 'succeeded',
            'claimed_at'        => now(),
            'completed_at'      => now(),
        ]);

        // Attempting to insert again should fail
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        DB::table('telegram_processed_callbacks')->insert([
            'callback_query_id' => $callbackId,
            'chat_id'           => 12345,
            'action'            => 'confirm_payment',
            'status'            => 'processing',
            'claimed_at'        => now(),
        ]);
    }

    public function test_failed_callback_can_be_retried(): void
    {
        $callbackId = 'cb_fail_retry_' . uniqid();

        // First attempt: claim and fail
        DB::table('telegram_processed_callbacks')->insert([
            'callback_query_id' => $callbackId,
            'chat_id'           => 12345,
            'action'            => 'confirm_payment',
            'status'            => 'failed',
            'error'             => 'DB connection lost',
            'claimed_at'        => now(),
            'completed_at'      => now(),
        ]);

        // Delete failed row (as claimCallback does)
        DB::table('telegram_processed_callbacks')
            ->where('callback_query_id', $callbackId)
            ->where('status', 'failed')
            ->delete();

        // Re-claim should succeed
        DB::table('telegram_processed_callbacks')->insert([
            'callback_query_id' => $callbackId,
            'chat_id'           => 12345,
            'action'            => 'confirm_payment',
            'status'            => 'processing',
            'claimed_at'        => now(),
        ]);

        $this->assertDatabaseHas('telegram_processed_callbacks', [
            'callback_query_id' => $callbackId,
            'status'            => 'processing',
        ]);
    }

    public function test_processing_callback_blocks_concurrent_claim(): void
    {
        $callbackId = 'cb_concurrent_' . uniqid();

        // First claim is in processing
        DB::table('telegram_processed_callbacks')->insert([
            'callback_query_id' => $callbackId,
            'chat_id'           => 12345,
            'action'            => 'confirm_payment',
            'status'            => 'processing',
            'claimed_at'        => now(),
        ]);

        // Second claim should fail
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        DB::table('telegram_processed_callbacks')->insert([
            'callback_query_id' => $callbackId,
            'chat_id'           => 12345,
            'action'            => 'confirm_payment',
            'status'            => 'processing',
            'claimed_at'        => now(),
        ]);
    }

    public function test_status_transition_from_processing_to_succeeded(): void
    {
        $callbackId = 'cb_transition_' . uniqid();

        DB::table('telegram_processed_callbacks')->insert([
            'callback_query_id' => $callbackId,
            'chat_id'           => 12345,
            'action'            => 'confirm_payment',
            'status'            => 'processing',
            'claimed_at'        => now(),
        ]);

        DB::table('telegram_processed_callbacks')
            ->where('callback_query_id', $callbackId)
            ->where('status', 'processing')
            ->update(['status' => 'succeeded', 'completed_at' => now()]);

        $this->assertDatabaseHas('telegram_processed_callbacks', [
            'callback_query_id' => $callbackId,
            'status'            => 'succeeded',
        ]);
    }

    public function test_status_transition_from_processing_to_failed(): void
    {
        $callbackId = 'cb_fail_' . uniqid();

        DB::table('telegram_processed_callbacks')->insert([
            'callback_query_id' => $callbackId,
            'chat_id'           => 12345,
            'action'            => 'confirm_expense',
            'status'            => 'processing',
            'claimed_at'        => now(),
        ]);

        DB::table('telegram_processed_callbacks')
            ->where('callback_query_id', $callbackId)
            ->where('status', 'processing')
            ->update(['status' => 'failed', 'error' => 'Test error', 'completed_at' => now()]);

        $this->assertDatabaseHas('telegram_processed_callbacks', [
            'callback_query_id' => $callbackId,
            'status'            => 'failed',
            'error'             => 'Test error',
        ]);
    }
}
