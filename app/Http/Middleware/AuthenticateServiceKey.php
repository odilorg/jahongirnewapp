<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\TelegramServiceKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticate internal API requests using X-Service-Key header.
 *
 * Validates:
 * 1. Key exists and is active (not expired, not revoked)
 * 2. Key is authorized for the requested bot slug
 * 3. Key is authorized for the requested action
 *
 * Audit-logs every request (success and failure).
 */
class AuthenticateServiceKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $keyValue = $request->header('X-Service-Key', '');

        if ($keyValue === '') {
            Log::warning('ServiceKey: missing X-Service-Key header', ['ip' => $request->ip()]);

            return response()->json(['error' => 'Missing X-Service-Key header'], 401);
        }

        $serviceKey = TelegramServiceKey::findByKey($keyValue);

        if ($serviceKey === null) {
            Log::warning('ServiceKey: unknown key', [
                'prefix' => substr($keyValue, 0, 12),
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'Invalid service key'], 401);
        }

        if (! $serviceKey->isValid()) {
            Log::warning('ServiceKey: inactive or expired', [
                'key_name' => $serviceKey->name,
                'prefix' => $serviceKey->key_prefix,
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'Service key is inactive or expired'], 403);
        }

        // Check slug authorization
        $slug = $request->route('slug');
        if ($slug && ! $serviceKey->canAccessSlug($slug)) {
            Log::warning('ServiceKey: slug not allowed', [
                'key_name' => $serviceKey->name,
                'slug' => $slug,
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'Not authorized for this bot'], 403);
        }

        // Check action authorization
        $action = $request->route()->getName();
        $actionShort = str_replace('internal.bots.', '', $action ?? '');
        if ($actionShort && ! $serviceKey->canPerformAction($actionShort)) {
            Log::warning('ServiceKey: action not allowed', [
                'key_name' => $serviceKey->name,
                'action' => $actionShort,
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'Not authorized for this action'], 403);
        }

        // Audit successful auth
        Log::info('ServiceKey: authorized', [
            'key_name' => $serviceKey->name,
            'slug' => $slug,
            'action' => $actionShort,
            'ip' => $request->ip(),
        ]);

        $serviceKey->touchLastUsed();

        // Store key on request for controller access
        $request->attributes->set('service_key', $serviceKey);

        return $next($request);
    }
}
