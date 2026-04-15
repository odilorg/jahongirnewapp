<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        'api/telegram/*',
        'api/booking/bot/webhook',
        '1/*',
        // Octobank server-to-server payment callback. Protected by the
        // shared OCTO_SECRET check inside OctoCallbackController, not CSRF.
        'octo/callback',
    ];
}
