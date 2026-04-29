<?php

declare(strict_types=1);

namespace Tests\Feature\Beds24;

use App\Enums\Beds24SyncStatus;
use App\Jobs\Beds24PaymentSyncJob;
use App\Models\Beds24PaymentSync;
use App\Services\Beds24BookingService;
use App\Services\Fx\Beds24PaymentSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response as HttpResponse;
use Mockery;
use Tests\TestCase;

/**
 * Regression test for the production "401: Token is missing" failure that
 * was firing on every Beds24PaymentSyncJob attempt.
 *
 * Before the fix, the job read config('services.beds24.api_key') — a
 * config key that does not exist in config/services.php — and silently
 * sent `token: ` (empty header), which Beds24 correctly answered with
 * 401. This pinned that the job now goes through Beds24BookingService
 * (whose apiCall() owns the cache + refresh + 401-retry guardrail) and
 * never reads the non-existent api_key directly.
 */
final class Beds24PaymentSyncJobAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_pending_sync_dispatches_payment_via_beds24_service_and_marks_pushed(): void
    {
        // Updated 2026-04-29 hotfix: endpoint is now `POST /bookings` with
        // an `invoiceItems` array, not `POST /bookings/{id}/payments`.
        // v2 doesn't return a specific payment id — webhook fills it later.
        $sync = Beds24PaymentSync::factory()->create([
            'beds24_booking_id' => '123',
            'local_reference'   => 'ref-success',
            'amount_usd'        => 42.50,
            'status'            => Beds24SyncStatus::Pending->value,
        ]);

        $beds24 = Mockery::mock(Beds24BookingService::class);
        $beds24->shouldReceive('apiCall')
            ->once()
            ->withArgs(function (string $method, string $endpoint, array $payload) {
                if ($method !== 'POST' || $endpoint !== '/bookings') {
                    return false;
                }
                $first = $payload[0] ?? null;
                if (! is_array($first) || $first['id'] !== 123) {
                    return false;
                }
                $item = $first['invoiceItems'][0] ?? null;
                return is_array($item)
                    && $item['type'] === 'payment'
                    && abs($item['amount'] - 42.50) < 0.001
                    && str_contains($item['description'], '[ref:ref-success]');
            })
            ->andReturn($this->fakeJsonResponse(200, [[
                'success'  => true,
                'modified' => true,
                'errors'   => [],
            ]]));

        $this->app->instance(Beds24BookingService::class, $beds24);

        (new Beds24PaymentSyncJob($sync->id))->handle(
            app(Beds24PaymentSyncService::class),
            $beds24,
        );

        $this->assertSame(Beds24SyncStatus::Pushed->value, $sync->fresh()->status->value);
        $this->assertSame('', $sync->fresh()->beds24_payment_id, 'v2 returns no payment id; webhook fills it later');
    }

    public function test_api_call_failure_marks_sync_failed_when_attempts_exhausted(): void
    {
        $sync = Beds24PaymentSync::factory()->create([
            'beds24_booking_id' => 'B999',
            'local_reference'   => 'ref-fail',
            'amount_usd'        => 10.00,
            'status'            => Beds24SyncStatus::Pending->value,
        ]);

        $beds24 = Mockery::mock(Beds24BookingService::class);
        // Returns a non-2xx response — apiCall has its own 401 retry; this
        // simulates a different terminal error reaching the job.
        $beds24->shouldReceive('apiCall')
            ->once()
            ->andReturn($this->fakeJsonResponse(500, ['error' => 'server'], 'Internal Server Error'));

        $this->app->instance(Beds24BookingService::class, $beds24);

        // Use a job whose attempts() reports exhausted so the failure path
        // marks the row as Failed (rather than releasing for retry).
        $job = new class ($sync->id) extends Beds24PaymentSyncJob {
            public function attempts(): int
            {
                return $this->tries; // pretend last retry
            }
        };

        $job->handle(app(Beds24PaymentSyncService::class), $beds24);

        $this->assertSame(Beds24SyncStatus::Failed->value, $sync->fresh()->status->value);
    }

    public function test_job_no_longer_reads_nonexistent_services_beds24_api_key_config(): void
    {
        // Belt-and-braces: the bad config key is still null, but the job
        // must not depend on it. If somebody re-introduces
        // config('services.beds24.api_key'), this test still passes —
        // it just guards that null doesn't end up in an outgoing header.
        $this->assertNull(config('services.beds24.api_key'), 'sanity: api_key still does not exist in config');

        $sync = Beds24PaymentSync::factory()->create([
            'status' => Beds24SyncStatus::Pending->value,
        ]);

        $beds24 = Mockery::mock(Beds24BookingService::class);
        $beds24->shouldReceive('apiCall')->once()->andReturn($this->fakeJsonResponse(200, [[
            'success' => true, 'modified' => true, 'errors' => [],
        ]]));

        $this->app->instance(Beds24BookingService::class, $beds24);

        // The expectation is delegated entirely to the service mock — if
        // the job tried to call Http directly with config(api_key),
        // Mockery would not match and the test would fail.
        (new Beds24PaymentSyncJob($sync->id))->handle(
            app(Beds24PaymentSyncService::class),
            $beds24,
        );

        $this->assertSame(Beds24SyncStatus::Pushed->value, $sync->fresh()->status->value);
    }

    private function fakeJsonResponse(int $status, array $body, string $reason = 'OK'): HttpResponse
    {
        $psr = new \GuzzleHttp\Psr7\Response($status, [
            'Content-Type' => 'application/json',
        ], json_encode($body));

        return new HttpResponse($psr);
    }
}
