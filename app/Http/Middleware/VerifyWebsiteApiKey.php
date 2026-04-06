<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guard the /api/bookings/website endpoint against unauthorized callers.
 *
 * Validates the static X-Api-Key header against WEBSITE_BOOKING_API_KEY in .env.
 * A static key is appropriate here: the only caller is mailer-tours.php on a
 * known server, and the key never changes at runtime.
 */
class VerifyWebsiteApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('services.website_booking_api_key');

        if (! $expected) {
            // Misconfigured environment — fail closed rather than open
            Log::error('VerifyWebsiteApiKey: WEBSITE_BOOKING_API_KEY is not configured');

            return response()->json(['error' => 'Service misconfigured'], 500);
        }

        $provided = $request->header('X-Api-Key', '');

        if (! hash_equals($expected, $provided)) {
            Log::warning('VerifyWebsiteApiKey: invalid or missing key', [
                'ip'     => $request->ip(),
                'prefix' => mb_substr($provided, 0, 8) ?: '(empty)',
            ]);

            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
