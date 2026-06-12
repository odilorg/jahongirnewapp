<?php

declare(strict_types=1);

namespace Tests\Feature\Beds24;

use App\Jobs\ForwardBeds24WebhookToHotelMgmtJob;
use App\Jobs\ProcessBeds24WebhookJob;
use App\Models\Beds24WebhookEvent;
use App\Models\IncomingWebhook;
use App\Services\HotelMgmtClient;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Beds24 → hotel-mgmt fan-out.
 *
 * The fan-out is additive and isolated: it must dispatch a copy to hotel-mgmt
 * when enabled, never when disabled, never for a confirmed duplicate, and it
 * must never affect the existing ProcessBeds24WebhookJob or the 200 ack.
 */
class Beds24FanoutTest extends TestCase
{
    /**
     * This repo's full migration chain is MySQL-bound and fragile under
     * migrate:fresh. handle() and the job only need two tables, so we run
     * them on an isolated in-memory sqlite connection — self-contained and
     * portable, mirroring WebhookIdempotencyTest's selective-migration idea.
     */
    private const MIGRATIONS = [
        'database/migrations/2026_03_17_300000_create_incoming_webhooks_table.php',
        'database/migrations/2026_03_18_100000_add_unique_event_id_to_incoming_webhooks.php',
        'database/migrations/2026_03_12_210000_create_beds24_webhook_events_table.php',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'services.beds24.enabled' => true,
            // Beds24BookingService reads this at construction (controller DI).
            'services.beds24.api_v2_refresh_token' => 'test-placeholder',
            'services.hotel_mgmt.webhook_url' => 'https://hotel.test/api/pms/beds24/webhook',
        ]);
        DB::purge('sqlite');

        foreach (self::MIGRATIONS as $path) {
            Artisan::call('migrate', ['--path' => $path, '--database' => 'sqlite', '--force' => true]);
        }
    }

    protected function tearDown(): void
    {
        Schema::connection('sqlite')->dropIfExists('beds24_webhook_events');
        Schema::connection('sqlite')->dropIfExists('incoming_webhooks');
        parent::tearDown();
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        return ['booking' => ['id' => 555, 'status' => 'confirmed'], 'invoiceItems' => []];
    }

    public function test_fanout_is_dispatched_when_enabled(): void
    {
        config(['services.hotel_mgmt.fanout_enabled' => true]);
        Queue::fake();

        $this->postJson('/api/beds24/webhook', $this->payload())->assertOk();

        Queue::assertPushed(ForwardBeds24WebhookToHotelMgmtJob::class);
        // Additive: the critical pipeline still runs.
        Queue::assertPushed(ProcessBeds24WebhookJob::class);
    }

    public function test_fanout_is_not_dispatched_when_disabled(): void
    {
        config(['services.hotel_mgmt.fanout_enabled' => false]);
        Queue::fake();

        $this->postJson('/api/beds24/webhook', $this->payload())->assertOk();

        Queue::assertNotPushed(ForwardBeds24WebhookToHotelMgmtJob::class);
        Queue::assertPushed(ProcessBeds24WebhookJob::class);
    }

    public function test_fanout_is_not_dispatched_for_a_confirmed_duplicate(): void
    {
        config(['services.hotel_mgmt.fanout_enabled' => true]);

        $payload = $this->payload();
        // Pre-seed a processed event with the hash the controller will compute.
        Beds24WebhookEvent::create([
            'event_hash' => hash('sha256', json_encode($payload)),
            'booking_id' => '555',
            'payload' => $payload,
            'status' => 'processed',
        ]);

        Queue::fake();
        $this->postJson('/api/beds24/webhook', $payload)->assertOk();

        Queue::assertNotPushed(ForwardBeds24WebhookToHotelMgmtJob::class);
    }

    public function test_job_does_nothing_when_flag_disabled(): void
    {
        config(['services.hotel_mgmt.fanout_enabled' => false]);
        $incoming = IncomingWebhook::create([
            'source' => 'beds24', 'event_id' => '1', 'payload' => $this->payload(), 'status' => 'pending',
        ]);

        $client = $this->recordingClient();
        (new ForwardBeds24WebhookToHotelMgmtJob($incoming->id))->handle($client);

        $this->assertFalse($client->called, 'Client must not be called when fan-out is disabled');
    }

    public function test_job_forwards_payload_when_enabled(): void
    {
        config(['services.hotel_mgmt.fanout_enabled' => true]);
        $incoming = IncomingWebhook::create([
            'source' => 'beds24', 'event_id' => '1', 'payload' => $this->payload(), 'status' => 'pending',
        ]);

        $client = $this->recordingClient(['ok' => true]);
        (new ForwardBeds24WebhookToHotelMgmtJob($incoming->id))->handle($client);

        $this->assertTrue($client->called);
        $this->assertSame(555, $client->lastPayload['booking']['id']);
    }

    public function test_job_throws_to_retry_on_downstream_failure(): void
    {
        config(['services.hotel_mgmt.fanout_enabled' => true]);
        $incoming = IncomingWebhook::create([
            'source' => 'beds24', 'event_id' => '1', 'payload' => $this->payload(), 'status' => 'pending',
        ]);

        $this->expectException(\RuntimeException::class);
        (new ForwardBeds24WebhookToHotelMgmtJob($incoming->id))->handle($this->recordingClient(['ok' => false, 'error' => 'http_503']));
    }

    /** A HotelMgmtClient double that records the call without doing HTTP. */
    private function recordingClient(array $result = ['ok' => true]): HotelMgmtClient
    {
        return new class($result) extends HotelMgmtClient
        {
            public bool $called = false;

            public ?array $lastPayload = null;

            public function __construct(private array $result) {}

            public function forwardBeds24Webhook(array $payload): array
            {
                $this->called = true;
                $this->lastPayload = $payload;

                return $this->result;
            }
        };
    }
}
