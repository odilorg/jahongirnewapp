<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BookingInquiry;
use App\Models\OctoPaymentAttempt;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Phase S — Octo callback signature guard.
 *
 * Step 1: presence check — callbacks without a `signature` field are
 * rejected with 403 regardless of the feature flag.
 *
 * Step 2: cryptographic check — only enforced when
 * `services.octo.verify_callback_signature` is true. Logs candidate
 * hashes in both modes so the exact scheme can be confirmed against
 * a real Octo callback.
 */
class OctoCallbackSignatureGuardTest extends TestCase
{
    use DatabaseTransactions;

    private function post(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(route('octo.callback'), $payload);
    }

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'shop_transaction_id' => 'inquiry_999_SIGTEST',
            'status'              => 'success',
            'total_sum'           => 5_000_000,
            'signature'           => 'TEST-SIG',
            'hash_key'            => 'test-hash-key',
        ], $overrides);
    }

    /** 1. Missing signature always returns 403. */
    public function test_missing_signature_returns_403(): void
    {
        $payload = $this->basePayload();
        unset($payload['signature']);

        $this->post($payload)->assertStatus(403);
    }

    /** 2. Empty signature string returns 403. */
    public function test_empty_signature_returns_403(): void
    {
        $this->post($this->basePayload(['signature' => '']))->assertStatus(403);
    }

    /** 3. With signature present and flag OFF, unknown transaction returns 404 (not 403). */
    public function test_unknown_transaction_with_signature_passes_guard(): void
    {
        config(['services.octo.verify_callback_signature' => false]);

        $this->post($this->basePayload())->assertStatus(404);
    }

    /** 4. With flag ON and wrong signature, returns 403. */
    public function test_wrong_signature_with_flag_on_returns_403(): void
    {
        config(['services.octo.verify_callback_signature' => true]);

        $this->post($this->basePayload(['signature' => 'WRONG-SIG']))->assertStatus(403);
    }

    /** 5. With flag OFF and wrong signature, guard passes (logs warning, continues). */
    public function test_wrong_signature_with_flag_off_passes_guard(): void
    {
        config(['services.octo.verify_callback_signature' => false]);

        // Unknown transaction → 404, but NOT 403 (guard passed, just no matching txn)
        $this->post($this->basePayload(['signature' => 'WRONG-SIG']))->assertStatus(404);
    }

    /** 6. Missing signature is logged at warning level. */
    public function test_missing_signature_is_logged(): void
    {
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')
            ->withArgs(fn ($msg) => str_contains($msg, 'missing signature'))
            ->once();

        $payload = $this->basePayload();
        unset($payload['signature']);
        $this->post($payload);
    }
}
