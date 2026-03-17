<?php

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
 * Configuration:
 *   CASHIER_BOT_WEBHOOK_SECRET=<random-string>  in .env
 *   config('services.cashier_bot.webhook_secret')
 *
 * Deployment:
 *   When registering the webhook with Telegram, include secret_token:
 *   curl "https://api.telegram.org/bot<TOKEN>/setWebhook?url=<URL>&secret_token=<SECRET>"
 */
class VerifyTelegramWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $expectedSecret = config('services.cashier_bot.webhook_secret', '');

        // Fail closed: if secret is not configured, reject all requests
        if (empty($expectedSecret)) {
            Log::error('VerifyTelegramWebhook: webhook_secret not configured — rejecting request');
            return response('Forbidden', 403);
        }

        $providedSecret = $request->header('X-Telegram-Bot-Api-Secret-Token', '');

        if (! hash_equals($expectedSecret, $providedSecret)) {
            Log::warning('VerifyTelegramWebhook: invalid secret token', [
                'ip' => $request->ip(),
            ]);
            return response('Forbidden', 403);
        }

        return $next($request);
    }
}
