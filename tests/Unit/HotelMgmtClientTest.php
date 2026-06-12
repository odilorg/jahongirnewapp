<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\HotelMgmtClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Unit tests for HotelMgmtClient — the thin adapter that forwards a Beds24
 * webhook copy to the hotel-mgmt PMS discovery receiver. It must NEVER throw
 * upward; it returns a result array and logs warnings on failure.
 */
class HotelMgmtClientTest extends TestCase
{
    private const URL = 'https://hotel-staging.example.test/api/pms/beds24/webhook';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.hotel_mgmt.webhook_url' => self::URL, 'services.hotel_mgmt.timeout' => 5]);
    }

    public function test_it_posts_the_payload_to_the_configured_url(): void
    {
        Http::fake([self::URL => Http::response('OK', 200)]);

        $result = (new HotelMgmtClient)->forwardBeds24Webhook(['booking' => ['id' => 123]]);

        $this->assertTrue($result['ok']);
        Http::assertSent(function ($request) {
            return $request->url() === self::URL
                && $request['booking']['id'] === 123;
        });
    }

    public function test_it_returns_not_ok_on_non_2xx_without_throwing(): void
    {
        Http::fake([self::URL => Http::response('boom', 503)]);

        $result = (new HotelMgmtClient)->forwardBeds24Webhook(['booking' => ['id' => 1]]);

        $this->assertFalse($result['ok']);
        $this->assertSame(503, $result['status']);
    }

    public function test_it_returns_not_ok_on_connection_exception_without_throwing(): void
    {
        Http::fake(function () {
            throw new ConnectionException('connection refused');
        });

        $result = (new HotelMgmtClient)->forwardBeds24Webhook(['booking' => ['id' => 1]]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('http_exception', $result['error']);
    }

    public function test_it_skips_when_no_url_configured(): void
    {
        config(['services.hotel_mgmt.webhook_url' => null]);
        Http::fake();

        $result = (new HotelMgmtClient)->forwardBeds24Webhook(['booking' => ['id' => 1]]);

        $this->assertFalse($result['ok']);
        $this->assertSame('no_url', $result['error']);
        Http::assertNothingSent();
    }
}
