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
 * Telegram sends this header on every webhook delivery if a `secret_token` was
 * provided when the webhook was registered via setWebhook API. The value must
 * match exactly. This middleware fails closed: if no secret is configured,
 * all requests are rejected.
 *
 * ## Usage (route definition)
 *
 *   Route::post('/telegram/cashier/webhook', ...)->middleware('verify.telegram.webhook:cashier');
 *   Route::post('/telegram/pos/webhook', ...)->middleware('verify.telegram.webhook:pos');
 *
 * The parameter is the bot slug, which maps to a config key for the webhook secret:
 *
 *   cashier     → services.cashier_bot.webhook_secret
 *   pos         → services.telegram_pos_bot.secret_token
 *   booking     → services.telegram_booking_bot.secret_token
 *   driver-guide → services.driver_guide_bot.webhook_secret
 *   owner-alert → services.owner_alert_bot.webhook_secret
 *   housekeeping → services.housekeeping_bot.webhook_secret
 *   kitchen     → services.kitchen_bot.webhook_secret
 *   main        → services.telegram.webhook_secret
 *
 * ## Deployment
 *
 *   When registering the webhook with Telegram, include secret_token:
 *   curl "https://api.telegram.org/bot<TOKEN>/setWebhook?url=<URL>&secret_token=<SECRET>"
 */
class VerifyTelegramWebhook
{
    /**
     * Slug → config key for webhook secret.
     * Must match the config keys in config/services.php.
     */
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
        if ($botSlug === '' || ! isset(self::SECRET_CONFIG_MAP[$botSlug])) {
            Log::error('VerifyTelegramWebhook: unknown or missing bot slug', [
                'slug' => $botSlug,
                'ip' => $request->ip(),
            ]);

            return response('Forbidden', 403);
        }

        $configKey = self::SECRET_CONFIG_MAP[$botSlug];
        $expectedSecret = (string) config($configKey, '');

        // Fail closed: if secret is not configured, reject all requests.
        // This forces operators to set the env var before the route works.
        if ($expectedSecret === '') {
            Log::error("VerifyTelegramWebhook: webhook secret not configured for [{$botSlug}]", [
                'config_key' => $configKey,
            ]);

            return response('Forbidden', 403);
        }

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
