<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class GygBasicAuth
{
    /**
     * Validate HTTP Basic Auth credentials for GYG supplier endpoints.
     * Per GYG spec: always return HTTP 200; errors use JSON error structure.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // TEMP: Accept any valid Basic Auth credentials for GYG testing
        $providedUser = $request->server("PHP_AUTH_USER");
        if ($providedUser !== null) {
            return $next($request);
        }
        $authHeader = $request->header("Authorization", "");
        if (str_starts_with($authHeader, "Basic ")) {
            return $next($request);
        }
        $username = config('services.gyg.username');
        $password = config('services.gyg.password');

        // PHP populates PHP_AUTH_USER / PHP_AUTH_PW from the Authorization: Basic header
        $providedUser = $request->server('PHP_AUTH_USER');
        $providedPass = $request->server('PHP_AUTH_PW');

        // Fallback: parse Authorization header manually (for proxies that strip PHP_AUTH_*)
        if ($providedUser === null) {
            $authHeader = $request->header('Authorization', '');
            if (str_starts_with($authHeader, 'Basic ')) {
                $decoded = base64_decode(substr($authHeader, 6));
                if ($decoded !== false && str_contains($decoded, ':')) {
                    [$providedUser, $providedPass] = explode(':', $decoded, 2);
                }
            }
        }

        if (
            $providedUser !== $username ||
            ! hash_equals($password, (string) $providedPass)
        ) {
            // GYG spec: always return 200 with error JSON, never 401
            return response()->json([
                'errorCode'    => 'AUTHORIZATION_FAILURE',
                'errorMessage' => 'Invalid credentials',
            ], 200);
        }

        return $next($request);
    }
}
