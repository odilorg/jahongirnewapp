<?php

namespace App\Http\Middleware;

use App\Services\TelegramPosService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateTelegramUser
{
    public function __construct(protected TelegramPosService $posService)
    {
    }
    
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $chatId = $request->input('message.chat.id') ?? $request->input('callback_query.message.chat.id');
        
        if (!$chatId) {
            return response('OK'); // Let webhook handler deal with it
        }
        
        $session = $this->posService->getSession($chatId);
        
        // If no session or not authenticated, the controller will handle it
        // This middleware is mainly for routes that absolutely require authentication
        
        if (!$session || !$session->isAuthenticated()) {
            // For webhook, we don't reject - we let the controller handle auth flow
            return $next($request);
        }
        
        return $next($request);
    }
}
