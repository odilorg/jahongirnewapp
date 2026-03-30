<?php

namespace Tests\Feature\Fx;

use App\Enums\Beds24SyncStatus;
use App\Models\Beds24PaymentSync;
use App\Models\CashTransaction;
use App\Services\Fx\Beds24PaymentSyncService;
use App\Services\Fx\WebhookReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private WebhookReconciliationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new WebhookReconciliationService(new Beds24PaymentSyncService());
    }

    /** @test */
    public function description_without_ref_returns_false(): void
    {
        $result = $this->service->reconcile('Direct Beds24 payment', []);

        $this->assertFalse($result);
    }

    /** @test */
    public function description_with_unknown_ref_returns_false(): void
    {
        $result = $this->service->reconcile('[ref:550e8400-e29b-41d4-a716-446655440000] Bot payment', []);

        $this->assertFalse($result);
    }

    /** @test */
    public function matching_ref_marks_sync_confirmed_and_returns_true(): void
    {
        $sync = $this->makePushedSync();

        $payload = ['booking_id' => 'B123', 'amount' => 150.0];
        $result  = $this->service->reconcile("[ref:{$sync->local_reference}] Bot payment", $payload);

        $this->assertTrue($result);

        $sync->refresh();
        $this->assertSame(Beds24SyncStatus::Confirmed, $sync->status);
        $this->assertNotNull($sync->webhook_confirmed_at);
        $this->assertSame($payload, $sync->webhook_raw_payload);
    }

    /** @test */
    public function reconcile_is_idempotent_on_double_webhook_delivery(): void
    {
        $sync = $this->makePushedSync();

        $this->service->reconcile("[ref:{$sync->local_reference}] Bot payment", []);
        $this->service->reconcile("[ref:{$sync->local_reference}] Bot payment", []); // duplicate

        $sync->refresh();
        $this->assertSame(Beds24SyncStatus::Confirmed, $sync->status);
    }

    /** @test */
    public function ref_extraction_is_case_insensitive(): void
    {
        $sync = $this->makePushedSync();

        $ref    = strtoupper($sync->local_reference);
        $result = $this->service->reconcile("[REF:{$ref}] bot payment", []);

        $this->assertTrue($result);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function makePushedSync(): Beds24PaymentSync
    {
        // Minimal CashTransaction needed for FK
        $tx = CashTransaction::factory()->create([
            'beds24_booking_id' => 'B123',
        ]);

        return Beds24PaymentSync::create([
            'cash_transaction_id' => $tx->id,
            'beds24_booking_id'   => 'B123',
            'local_reference'     => \Illuminate\Support\Str::uuid()->toString(),
            'amount_usd'          => 150.0,
            'status'              => Beds24SyncStatus::Pushed->value,
        ]);
    }
}
