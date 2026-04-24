<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BookingInquiry;
use App\Services\OctoPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fallback contract for postToOcto().
 *
 * Jahongir VPS periodically loses routing to secure.octo.uz; the service
 * must transparently retry via the CF-proxied relay. These tests lock
 * the behavior explicitly — silent regression here would ship bad
 * payment links during the next ISP outage.
 *
 * Uses Http::fake() so no infrastructure is needed. Each scenario
 * exercises the SAME public entry point (createPaymentLinkForInquiry)
 * since that's what drives all production call sites — the internal
 * postToOcto() method is covered implicitly.
 */
class OctoPaymentServiceRelayFallbackTest extends TestCase
{
    use RefreshDatabase;

    private const OCTO_URL       = 'https://secure.octo.uz/prepare_payment';
    private const RELAY_URL      = 'https://octo-relay.jahongir-app.uz/prepare_payment';
    private const RELAY_SECRET   = 'test-secret-abc';
    private const SUCCESS_BODY   = [
        'error' => 0,
        'data'  => [
            'octo_pay_url'    => 'https://pay2.octo.uz/pay/test-uuid',
            'shop_transaction_id' => 'inquiry_1_mock',
            'status'          => 'created',
            'total_sum'       => 1_000_000,
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.octo.shop_id'      => 27061,
            'services.octo.secret'       => 'shop-secret',
            'services.octo.url'          => self::OCTO_URL,
            'services.octo.tsp_id'       => null,
            'services.octo.relay_url'    => self::RELAY_URL,
            'services.octo.relay_secret' => self::RELAY_SECRET,
            // Skip live exchange-rate fetches — deterministic rate so tests
            // don't depend on the service's 5-layer fallback.
            'services.octo.fallback_usd_uzs_rate' => 12500,
            'cache.default'              => 'array',
        ]);
    }

    private function makeInquiry(): BookingInquiry
    {
        return BookingInquiry::create([
            'reference'          => 'INQ-TEST-' . uniqid(),
            'source'             => 'website',
            'status'             => BookingInquiry::STATUS_NEW,
            'customer_name'      => 'Test Guest',
            'customer_phone'     => '+998901234567',
            'customer_email'     => 't@e.st',
            'tour_name_snapshot' => 'Test Tour',
            'people_adults'      => 2,
            'people_children'    => 0,
            'submitted_at'       => now(),
        ]);
    }

    /** The hot path — when relay bails us out of a direct-path 5xx. */
    public function test_falls_back_to_relay_when_direct_returns_5xx(): void
    {
        // Force exchange-rate fetches to fail so service uses .env fallback.
        // We fake ONLY the URLs we care about and let others hit a stub.
        Http::fake([
            'cbu.uz/*'            => Http::response(null, 500),
            'open.er-api.com/*'   => Http::response(null, 500),
            'cdn.jsdelivr.net/*'  => Http::response(null, 500),
            'currency-api.pages.dev/*' => Http::response(null, 500),
            self::OCTO_URL        => Http::response(null, 502),
            self::RELAY_URL       => Http::response(self::SUCCESS_BODY, 200),
        ]);

        $result = app(OctoPaymentService::class)
            ->createPaymentLinkForInquiry($this->makeInquiry(), 100.00);

        $this->assertStringContainsString('pay2.octo.uz', $result['url']);

        // Both endpoints were hit — direct failed, relay saved the day.
        Http::assertSent(fn ($req) => $req->url() === self::OCTO_URL);
        Http::assertSent(fn ($req) => $req->url() === self::RELAY_URL
            && $req->header('X-Relay-Secret')[0] === self::RELAY_SECRET);
    }

    /** Timeouts throw ConnectionException — relay must still catch that. */
    public function test_falls_back_to_relay_when_direct_throws_timeout(): void
    {
        Http::fake([
            'cbu.uz/*'            => Http::response(null, 500),
            'open.er-api.com/*'   => Http::response(null, 500),
            'cdn.jsdelivr.net/*'  => Http::response(null, 500),
            'currency-api.pages.dev/*' => Http::response(null, 500),
            self::OCTO_URL        => fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Connection timed out'),
            self::RELAY_URL       => Http::response(self::SUCCESS_BODY, 200),
        ]);

        $result = app(OctoPaymentService::class)
            ->createPaymentLinkForInquiry($this->makeInquiry(), 100.00);

        $this->assertStringContainsString('pay2.octo.uz', $result['url']);
        Http::assertSent(fn ($req) => $req->url() === self::RELAY_URL);
    }

    /** When relay is not configured, direct failure must propagate to caller. */
    public function test_direct_failure_propagates_when_relay_not_configured(): void
    {
        config([
            'services.octo.relay_url'    => null,
            'services.octo.relay_secret' => null,
        ]);

        Http::fake([
            'cbu.uz/*'            => Http::response(null, 500),
            'open.er-api.com/*'   => Http::response(null, 500),
            'cdn.jsdelivr.net/*'  => Http::response(null, 500),
            'currency-api.pages.dev/*' => Http::response(null, 500),
            self::OCTO_URL        => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'),
        ]);

        $this->expectException(\Throwable::class);

        app(OctoPaymentService::class)
            ->createPaymentLinkForInquiry($this->makeInquiry(), 100.00);

        // Relay URL was never contacted (config is null).
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'octo-relay'));
    }

    /** Catastrophic case — both paths down must throw, NOT silently succeed. */
    public function test_throws_when_both_direct_and_relay_fail(): void
    {
        Http::fake([
            'cbu.uz/*'            => Http::response(null, 500),
            'open.er-api.com/*'   => Http::response(null, 500),
            'cdn.jsdelivr.net/*'  => Http::response(null, 500),
            'currency-api.pages.dev/*' => Http::response(null, 500),
            self::OCTO_URL        => Http::response(null, 502),
            self::RELAY_URL       => Http::response(['error' => 'relay down'], 503),
        ]);

        // Caller's existing `if (! $response->successful())` branch in
        // createPaymentLinkForInquiry throws with the relay response body.
        // The critical contract: caller must NOT receive a "success" result
        // when both paths failed — that would silently mark the inquiry as
        // awaiting_payment on a URL that doesn't exist.
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Octo payment link creation failed/');

        app(OctoPaymentService::class)
            ->createPaymentLinkForInquiry($this->makeInquiry(), 100.00);
    }
}
