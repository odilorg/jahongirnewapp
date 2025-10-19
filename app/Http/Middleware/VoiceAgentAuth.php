<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VoiceAgentAuth
{
    /**
     * Handle an incoming request from the voice agent.
     * Validates API key from Authorization header.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->header('Authorization');
        
        // Extract Bearer token if present
        if (str_starts_with($apiKey, 'Bearer ')) {
            $apiKey = substr($apiKey, 7);
        }
        
        // Get expected API key from environment
        $expectedKey = env('VOICE_AGENT_API_KEY');
        
        // If no API key configured, allow (for backward compatibility during setup)
        if (empty($expectedKey)) {
            \Log::warning('Voice Agent API called without configured API key');
            return $next($request);
        }
        
        // Validate API key
        if ($apiKey !== $expectedKey) {
            \Log::warning('Voice Agent unauthorized access attempt', [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Unauthorized - Invalid API key',
            ], 401);
        }
        
        // API key is valid, proceed
        return $next($request);
    }
}
