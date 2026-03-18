<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verify Telegram webhook requests using the X-Telegram-Bot-Api-Secret-Token header.
 *
 * ## Staged rollout (migration mode)
 *
 * Each bot transitions through two states:
 *
 *   1. UNENFORCED (webhook_secret env var is empty or not set):
 *      - Requests pass through WITHOUT header verification
 *      - A warning is logged once per request so operators know which bots
 *        still need migration
 *      - This is the safe default during rollout — no traffic is dropped
 *
 *   2. ENFORCED (webhook_secret env var is set to a non-empty value):
 *      - X-Telegram-Bot-Api-Secret-Token header MUST match (hash_equals)
 *      - Missing or wrong header → 403 Forbidden
 *      - This is fail-closed: once the secret is set, all unsigned
 *        requests are rejected
 *
 * Operators enable enforcement per bot by:
 *   1. Generating a secret:  openssl rand -hex 32
 *   2. Setting the env var:  CASHIER_BOT_WEBHOOK_SECRET=<secret>
 *   3. Re-registering the webhook with Telegram including secret_token
 *   4. Verifying the bot still receives updates
 *
 * ## Usage
 *
 *   Route::post('/telegram/cashier/webhook', ...)->middleware('verify.telegram.webhook:cashier');
 *   Route::post('/telegram/pos/webhook', ...)->middleware('verify.telegram.webhook:pos');
 *
 * ## Slug → config key mapping
 *
 *   cashier      → services.cashier_bot.webhook_secret
 *   pos          → services.telegram_pos_bot.secret_token
 *   booking      → services.telegram_booking_bot.secret_token
 *   driver-guide → services.driver_guide_bot.webhook_secret
 *   owner-alert  → services.owner_alert_bot.webhook_secret
 *   housekeeping → services.housekeeping_bot.webhook_secret
 *   kitchen      → services.kitchen_bot.webhook_secret
 *   main         → services.telegram.webhook_secret
 */
class VerifyTelegramWebhook
{
    private const SECRET_CONFIG_MAP = [
        'cashier' => 'services.cashier_bot.webhook_secret',
        'pos' => 'services.telegram_pos_bot.secret_token',
        'booking' => 'services.telegram_booking_bot.secret_token',
        'driver-guide' => 'services.driver_guide_bot.webhook_secret',
        'owner-alert' => 'services.owner_alert_bot.webhook_secret',
        'housekeeping' => 'services.housekeeping_bot.webhook_secret',
        'kitchen' => 'services.kitchen_bot.webhook_secret',
        'main' => 'services.telegram.webhook_secret',
    ];

    /**
     * @param string $botSlug Bot slug identifying which webhook secret to check
     */
    public function handle(Request $request, Closure $next, string $botSlug = ''): Response
    {
        // Unknown or missing slug → hard reject (code error, not config error)
        if ($botSlug === '' || ! isset(self::SECRET_CONFIG_MAP[$botSlug])) {
            Log::error('VerifyTelegramWebhook: unknown or missing bot slug', [
                'slug' => $botSlug,
                'ip' => $request->ip(),
            ]);

            return response('Forbidden', 403);
        }

        $configKey = self::SECRET_CONFIG_MAP[$botSlug];
        $expectedSecret = (string) config($configKey, '');

        // ── MIGRATION MODE ──────────────────────────────────────────
        // Secret not configured yet → pass through with warning.
        // This allows bot-by-bot cutover: set the env var when ready,
        // and enforcement kicks in immediately for that bot only.
        if ($expectedSecret === '') {
            Log::warning("VerifyTelegramWebhook: [{$botSlug}] has no webhook secret configured — passing request UNENFORCED", [
                'config_key' => $configKey,
                'ip' => $request->ip(),
            ]);

            return $next($request);
        }

        // ── ENFORCED MODE ───────────────────────────────────────────
        // Secret IS configured → header MUST match.
        $providedSecret = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');

        if (! hash_equals($expectedSecret, $providedSecret)) {
            Log::warning("VerifyTelegramWebhook: invalid secret for [{$botSlug}]", [
                'ip' => $request->ip(),
            ]);

            return response('Forbidden', 403);
        }

        return $next($request);
    }
}
