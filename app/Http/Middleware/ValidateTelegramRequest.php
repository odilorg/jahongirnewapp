<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;

class ValidateTelegramRequest
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // In production, you should validate the request is actually from Telegram
        // For now, we'll do basic validation
        
        $token = config('services.telegram_pos_bot.token');
        
        if (!$token) {
            Log::error('Telegram POS bot token not configured');
            return response('Unauthorized', 401);
        }
        
        // Validate that the request contains expected Telegram webhook structure
        if (!$request->has('update_id')) {
            Log::warning('Invalid Telegram webhook request - missing update_id');
            // Still allow the request in development
            // In production, you might want to reject it:
            // return response('Invalid request', 400);
        }
        
        // Optional: Validate request signature if using secret token
        // $secretToken = $request->header('X-Telegram-Bot-Api-Secret-Token');
        // if ($secretToken !== config('services.telegram_pos_bot.secret_token')) {
        //     return response('Unauthorized', 401);
        // }
        
        return $next($request);
    }
}
