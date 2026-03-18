<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Middleware\VerifyTelegramWebhook;
use Illuminate\Http\Request;
use Tests\TestCase;

class VerifyTelegramWebhookTest extends TestCase
{
    private VerifyTelegramWebhook $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new VerifyTelegramWebhook();
    }

    // ──────────────────────────────────────────────
    // Unknown/missing slug → always 403
    // ──────────────────────────────────────────────

    /** @test */
    public function rejects_empty_slug(): void
    {
        $request = Request::create('/webhook', 'POST');

        $response = $this->middleware->handle($request, fn () => response('OK'), '');

        $this->assertSame(403, $response->getStatusCode());
    }

    /** @test */
    public function rejects_unknown_slug(): void
    {
        $request = Request::create('/webhook', 'POST');

        $response = $this->middleware->handle($request, fn () => response('OK'), 'nonexistent-bot');

        $this->assertSame(403, $response->getStatusCode());
    }

    // ──────────────────────────────────────────────
    // Migration mode: secret not configured → pass through
    // ──────────────────────────────────────────────

    /** @test */
    public function passes_through_when_secret_not_configured(): void
    {
        config(['services.kitchen_bot.webhook_secret' => '']);

        $request = Request::create('/webhook', 'POST');

        $response = $this->middleware->handle($request, fn () => response('OK'), 'kitchen');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('OK', $response->getContent());
    }

    /** @test */
    public function passes_through_when_secret_null(): void
    {
        config(['services.housekeeping_bot.webhook_secret' => null]);

        $request = Request::create('/webhook', 'POST');

        $response = $this->middleware->handle($request, fn () => response('OK'), 'housekeeping');

        $this->assertSame(200, $response->getStatusCode());
    }

    // ──────────────────────────────────────────────
    // Enforced mode: secret configured → must match
    // ──────────────────────────────────────────────

    /** @test */
    public function passes_with_correct_secret(): void
    {
        config(['services.cashier_bot.webhook_secret' => 'my-secret-123']);

        $request = Request::create('/webhook', 'POST');
        $request->headers->set('X-Telegram-Bot-Api-Secret-Token', 'my-secret-123');

        $response = $this->middleware->handle($request, fn () => response('OK'), 'cashier');

        $this->assertSame(200, $response->getStatusCode());
    }

    /** @test */
    public function rejects_with_wrong_secret(): void
    {
        config(['services.cashier_bot.webhook_secret' => 'correct-secret']);

        $request = Request::create('/webhook', 'POST');
        $request->headers->set('X-Telegram-Bot-Api-Secret-Token', 'wrong-secret');

        $response = $this->middleware->handle($request, fn () => response('OK'), 'cashier');

        $this->assertSame(403, $response->getStatusCode());
    }

    /** @test */
    public function rejects_with_missing_header_when_secret_configured(): void
    {
        config(['services.cashier_bot.webhook_secret' => 'configured-secret']);

        $request = Request::create('/webhook', 'POST');
        // No X-Telegram-Bot-Api-Secret-Token header

        $response = $this->middleware->handle($request, fn () => response('OK'), 'cashier');

        $this->assertSame(403, $response->getStatusCode());
    }

    // ──────────────────────────────────────────────
    // All slugs resolve to a config key
    // ──────────────────────────────────────────────

    /** @test */
    public function all_eight_slugs_are_recognized(): void
    {
        $slugs = ['cashier', 'pos', 'booking', 'driver-guide', 'owner-alert', 'housekeeping', 'kitchen', 'main'];

        foreach ($slugs as $slug) {
            $request = Request::create('/webhook', 'POST');
            // With no secret configured, these should all pass through (migration mode)
            $response = $this->middleware->handle($request, fn () => response('OK'), $slug);

            // Either 200 (pass-through, no secret configured) or a valid response
            // — NOT 403 from "unknown slug"
            $this->assertTrue(
                $response->getStatusCode() === 200,
                "Slug [{$slug}] was not recognized by middleware"
            );
        }
    }

    // ──────────────────────────────────────────────
    // Per-bot enforcement isolation
    // ──────────────────────────────────────────────

    /** @test */
    public function enforcing_one_bot_does_not_affect_others(): void
    {
        // Cashier is enforced
        config(['services.cashier_bot.webhook_secret' => 'cashier-secret']);
        // Kitchen is NOT enforced (empty)
        config(['services.kitchen_bot.webhook_secret' => '']);

        // Cashier without header → 403
        $cashierReq = Request::create('/webhook', 'POST');
        $cashierResp = $this->middleware->handle($cashierReq, fn () => response('OK'), 'cashier');
        $this->assertSame(403, $cashierResp->getStatusCode());

        // Kitchen without header → 200 (migration mode)
        $kitchenReq = Request::create('/webhook', 'POST');
        $kitchenResp = $this->middleware->handle($kitchenReq, fn () => response('OK'), 'kitchen');
        $this->assertSame(200, $kitchenResp->getStatusCode());
    }

    /** @test */
    public function pos_bot_uses_secret_token_config_key(): void
    {
        // POS uses 'secret_token' key, not 'webhook_secret'
        config(['services.telegram_pos_bot.secret_token' => 'pos-secret']);

        $request = Request::create('/webhook', 'POST');
        $request->headers->set('X-Telegram-Bot-Api-Secret-Token', 'pos-secret');

        $response = $this->middleware->handle($request, fn () => response('OK'), 'pos');

        $this->assertSame(200, $response->getStatusCode());
    }
}
