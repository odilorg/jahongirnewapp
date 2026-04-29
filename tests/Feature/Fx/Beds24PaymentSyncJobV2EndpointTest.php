<?php

declare(strict_types=1);

namespace Tests\Feature\Fx;

use App\Enums\Beds24SyncStatus;
use App\Jobs\Beds24PaymentSyncJob;
use App\Models\Beds24PaymentSync;
use App\Models\CashTransaction;
use App\Models\CashierShift;
use App\Models\User;
use App\Services\Beds24BookingService;
use App\Services\Fx\Beds24PaymentSyncService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Response as HttpResponse;
use Mockery;
use Tests\TestCase;

/**
 * Hotfix verification — Beds24 v2 payment-push endpoint correction.
 *
 * Background (2026-04-29): the previous push code targeted the non-existent
 * `POST /bookings/{id}/payments` endpoint and returned HTTP 500 on every
 * attempt. Three production rows accumulated >1000 failed retries before
 * being manually skipped on 2026-04-27 / 2026-04-29. No payment ever
 * reached Beds24 via this code path.
 *
 * The fix uses the canonical v2 endpoint:
 *   POST /bookings  [{ id, invoiceItems: [{ type:"payment", amount, description }] }]
 *
 * These tests pin:
 *   1. Endpoint + payload shape
 *   2. Description carries the [ref:UUID] webhook-matching token
 *   3. Success transitions the row to Pushed
 *   4. v2 errors array fails loudly
 *   5. Non-2xx response fails loudly
 *   6. Bare-string response (no array wrapper) fails loudly
 */
final class Beds24PaymentSyncJobV2EndpointTest extends TestCase
{
    use DatabaseTransactions;

    private Beds24BookingService $beds24Mock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->beds24Mock = Mockery::mock(Beds24BookingService::class);
        $this->app->instance(Beds24BookingService::class, $this->beds24Mock);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_pushes_payment_to_v2_bookings_endpoint_with_invoice_item(): void
    {
        $sync = $this->makePendingSync(amountUsd: 67.39, beds24BookingId: '84571251');

        $this->beds24Mock->shouldReceive('apiCall')
            ->once()
            ->withArgs(function (string $method, string $endpoint, array $payload = []) use ($sync) {
                $this->assertSame('POST', $method);
                $this->assertSame('/bookings', $endpoint, 'Must use /bookings, not /bookings/{id}/payments');
                $this->assertIsArray($payload);
                $this->assertCount(1, $payload, 'Top-level payload is an array of one booking update');

                $first = $payload[0];
                $this->assertSame(84571251, $first['id'], 'Booking id must be int');
                $this->assertCount(1, $first['invoiceItems']);

                $item = $first['invoiceItems'][0];
                $this->assertSame('payment', $item['type']);
                $this->assertEqualsWithDelta(67.39, $item['amount'], 0.001);
                $this->assertStringContainsString("[ref:{$sync->local_reference}]", $item['description'],
                    'Description MUST include [ref:UUID] for webhook reconciliation');
                $this->assertStringContainsString('Bot payment', $item['description']);

                return true;
            })
            ->andReturn($this->fakeOk());

        $job = new Beds24PaymentSyncJob($sync->id);
        $job->handle($this->app->make(Beds24PaymentSyncService::class), $this->beds24Mock);

        $fresh = $sync->fresh();
        $this->assertSame(Beds24SyncStatus::Pushed, $fresh->status);
        $this->assertSame('', $fresh->beds24_payment_id, 'v2 does not return a payment id; webhook will fill it');
        $this->assertNull($fresh->last_error);
    }

    public function test_v2_response_with_success_false_marks_row_failed(): void
    {
        $sync = $this->makePendingSync();

        $this->beds24Mock->shouldReceive('apiCall')
            ->once()
            ->andReturn($this->fakeResponse(200, [[
                'success' => false,
                'errors'  => [['code' => 'invalid', 'message' => 'Booking is locked']],
            ]]));

        $this->expectJobFailureMode($sync, 'Booking is locked');
    }

    public function test_v2_response_with_errors_array_marks_row_failed(): void
    {
        $sync = $this->makePendingSync();

        $this->beds24Mock->shouldReceive('apiCall')
            ->once()
            ->andReturn($this->fakeResponse(200, [[
                'success' => false,
                'errors'  => [['code' => 1, 'message' => 'Invalid invoiceItem.type']],
            ]]));

        $this->expectJobFailureMode($sync, 'Invalid invoiceItem.type');
    }

    public function test_non_2xx_http_response_marks_row_failed(): void
    {
        $sync = $this->makePendingSync();

        $this->beds24Mock->shouldReceive('apiCall')
            ->once()
            ->andReturn($this->fakeResponse(500, ['success' => false, 'error' => 'Could not process request']));

        $this->expectJobFailureMode($sync, 'Beds24 API error 500');
    }

    public function test_v2_partial_success_with_errors_marks_row_failed(): void
    {
        $sync = $this->makePendingSync();

        // Beds24 v2 partial-success: success=true but errors array non-empty.
        // Without this guard, we'd incorrectly mark the row Pushed.
        $this->beds24Mock->shouldReceive('apiCall')
            ->once()
            ->andReturn($this->fakeResponse(200, [[
                'success'  => true,
                'modified' => true,
                'errors'   => [['code' => 1, 'message' => 'invoiceItem partially applied']],
            ]]));

        $this->expectJobFailureMode($sync, 'partial success');
    }

    public function test_unexpected_response_shape_marks_row_failed(): void
    {
        $sync = $this->makePendingSync();

        $this->beds24Mock->shouldReceive('apiCall')
            ->once()
            ->andReturn($this->fakeResponse(200, 'just a string, not the expected array'));

        $this->expectJobFailureMode($sync, 'unexpected response shape');
    }

    private function expectJobFailureMode(Beds24PaymentSync $sync, string $errorContains): void
    {
        $job = new Beds24PaymentSyncJob($sync->id);

        // The job catches throwables internally; we just need to verify the row was transitioned.
        $job->handle($this->app->make(Beds24PaymentSyncService::class), $this->beds24Mock);

        $fresh = $sync->fresh();
        // Non-exhausted attempt: row goes back to Pending so the queue can retry; on exhaustion it would be Failed.
        $this->assertContains($fresh->status, [Beds24SyncStatus::Pending, Beds24SyncStatus::Failed],
            'Failed push must leave the row pending or failed, never pushed');
        $this->assertStringContainsString($errorContains, (string) $fresh->last_error);
    }

    private function makePendingSync(float $amountUsd = 100.0, string $beds24BookingId = '99999999'): Beds24PaymentSync
    {
        $shift = CashierShift::factory()->create(['status' => 'open', 'opened_at' => now()]);
        $user  = User::factory()->create();

        $tx = CashTransaction::create([
            'cashier_shift_id'  => $shift->id,
            'type'              => 'in',
            'amount'            => $amountUsd,
            'currency'          => 'USD',
            'category'          => 'sale',
            'beds24_booking_id' => $beds24BookingId,
            'payment_method'    => 'cash',
            'source_trigger'    => 'cashier_bot',
            'created_by'        => $user->id,
            'occurred_at'       => now(),
        ]);

        return Beds24PaymentSync::create([
            'cash_transaction_id' => $tx->id,
            'beds24_booking_id'   => $beds24BookingId,
            'local_reference'     => (string) \Illuminate\Support\Str::uuid(),
            'amount_usd'          => $amountUsd,
            'status'              => Beds24SyncStatus::Pending->value,
        ]);
    }

    private function fakeOk(): HttpResponse
    {
        return $this->fakeResponse(200, [[
            'success'  => true,
            'modified' => true,
            'info'     => [['field' => 'invoiceItems', 'message' => 'created']],
            'errors'   => [],
        ]]);
    }

    private function fakeResponse(int $status, mixed $json): HttpResponse
    {
        return new HttpResponse(
            new \GuzzleHttp\Psr7\Response($status, [], json_encode($json))
        );
    }
}
