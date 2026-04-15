<?php

use Illuminate\Http\Request;
use App\Filament\Pages\Availability;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TelegramController;
use App\Http\Controllers\TelegramDriverGuideSignUpController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\OpsBotController;
use App\Http\Controllers\WebsiteBookingController;
use App\Http\Controllers\Api\BookingInquiryController;


/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::post('/availability', [Availability::class, 'checkAvailability'])->middleware('auth:sanctum');
// Route::middleware('auth:sanctum')->group(function () {
//     Route::apiResource('ai-instructions', AiInstructionController::class);
//     // Or if you want to expose specific methods:
//     // Route::get('ai-instructions', [AiInstructionController::class, 'index']);
//     // Route::post('ai-instructions', [AiInstructionController::class, 'store']);
//     // etc...
// });

// Route::middleware('auth:sanctum')->group(function () {
//     Route::post('/sys-instructions', [SysInstructionController::class, 'store']);
// });

Route::post('/telegram/webhook', [TelegramController::class, 'handleWebhook'])
    ->middleware('verify.telegram.webhook:main');
Route::post('/telegram/driver_guide_signup', [TelegramDriverGuideSignUpController::class, 'handleWebhook'])
    ->middleware('verify.telegram.webhook:driver-guide');


Route::post('/webhook/tour-booking', [WebhookController::class, 'handleTourBooking']);

// Website booking form → DB pipeline (LEGACY — kept for reference, not called)
Route::post('/bookings/website', [WebsiteBookingController::class, 'store'])
    ->middleware(['website.api_key', 'throttle:30,1'])
    ->name('bookings.website');

// Website inquiry pipeline (new, decoupled from legacy tours/bookings)
// Called by jahongir-travel.uz mailer-tours.php. Throttle = 10/min per IP.
Route::prefix('v1')->group(function () {
    Route::post('/inquiries', [BookingInquiryController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('inquiries.store');
});


// Telegram Bot Availability Routes
Route::post('/telegram/bot/webhook', [\App\Http\Controllers\TelegramWebhookController::class, 'handle'])
    ->middleware('verify.telegram.webhook:booking')
    ->name('telegram.webhook');
Route::post('/telegram/bot/set-webhook', [\App\Http\Controllers\TelegramWebhookController::class, 'setWebhook'])->middleware('auth:sanctum');
Route::get('/telegram/bot/webhook-info', [\App\Http\Controllers\TelegramWebhookController::class, 'getWebhookInfo'])->middleware('auth:sanctum');

// Ops Bot — operator manual booking entry (@JahongirOpsBot)
Route::post('/telegram/ops/webhook', [OpsBotController::class, 'webhook'])
    ->name('ops_bot.webhook');

// Booking Bot Webhook
Route::post('/booking/bot/webhook', [\App\Http\Controllers\BookingWebhookController::class, 'handle'])
    ->middleware('verify.telegram.webhook:booking')
    ->name('booking.bot.webhook');

// Telegram POS Bot Routes
Route::post('/telegram/pos/webhook', [\App\Http\Controllers\TelegramPosController::class, 'handleWebhook'])
    ->middleware('verify.telegram.webhook:pos')
    ->name('telegram.pos.webhook');

Route::post('/telegram/pos/set-webhook', [\App\Http\Controllers\TelegramPosController::class, 'setWebhook'])
    ->middleware('auth:sanctum');

Route::get('/telegram/pos/webhook-info', [\App\Http\Controllers\TelegramPosController::class, 'getWebhookInfo'])
    ->middleware('auth:sanctum');

// Voice Agent API Routes
Route::middleware('auth:sanctum')->prefix('voice-agent')->group(function () {
    // Route removed: VoiceAgent (unused)
    // Route removed: VoiceAgent (unused)
    // Route removed: VoiceAgent (unused)
});

// Beds24 Webhook — receives booking notifications from Beds24
// No auth middleware: Beds24 calls this without credentials.
// URL configured in Beds24: https://jahongir-app.uz/api/beds24/webhook
Route::post('/beds24/webhook', [\App\Http\Controllers\Beds24WebhookController::class, 'handle'])
    ->name('beds24.webhook');

// Phase 2: Cashier Bot (admin payment/expense logging)
Route::post('/telegram/cashier/webhook', [\App\Http\Controllers\CashierBotController::class, 'handleWebhook'])
    ->middleware('verify.telegram.webhook:cashier')
    ->name('telegram.cashier.webhook');

Route::post("/telegram/owner/webhook", [\App\Http\Controllers\OwnerBotController::class, "handleWebhook"])
    ->middleware('verify.telegram.webhook:owner-alert')
    ->name("telegram.owner.webhook");


// ── Health / version endpoint ────────────────────────────────────────────────
// No auth required. Reads $APP_DIR/.version (written by deploy script).
// Falls back to live git commands if the file doesn't exist.
Route::get('/healthz', function () {
    $versionFile = base_path('.version');

    if (file_exists($versionFile)) {
        $lines = parse_ini_file($versionFile);
        $sha        = $lines['SHA']         ?? 'unknown';
        $tag        = $lines['TAG']         ?? 'unknown';
        $deployedAt = $lines['DEPLOYED_AT'] ?? 'unknown';
    } else {
        // Fallback: shell out (only happens before first deploy with new script)
        $sha        = trim(shell_exec('git -C ' . escapeshellarg(base_path()) . ' rev-parse HEAD 2>/dev/null') ?? 'unknown');
        $tag        = trim(shell_exec('git -C ' . escapeshellarg(base_path()) . ' describe --tags --exact-match 2>/dev/null') ?? 'no-tag');
        $deployedAt = 'unknown';
    }

    // Redis health — cache depends on it
    $redisOk = false;
    try {
        \Illuminate\Support\Facades\Redis::ping();
        $redisOk = true;
    } catch (\Throwable $e) {
        // Redis down
    }

    $status = $redisOk ? 'ok' : 'degraded';

    return response()->json([
        'status'      => $status,
        'sha'         => $sha,
        'tag'         => $tag,
        'deployed_at' => $deployedAt,
        'php'         => PHP_VERSION,
        'laravel'     => app()->version(),
        'redis'       => $redisOk ? 'ok' : 'down',
        'cache'       => config('cache.default'),
    ]);
})->name('healthz');

// Beds24 token health check
Route::get('/beds24/health', function () {
    $service = app(\App\Services\Beds24BookingService::class);
    return response()->json($service->getTokenStatus());
})->name('beds24.health');
// Housekeeping Bot Webhook
Route::post('/telegram/housekeeping/webhook', [\App\Http\Controllers\HousekeepingBotController::class, 'handleWebhook'])
    ->middleware('verify.telegram.webhook:housekeeping')
    ->name('telegram.housekeeping.webhook');

// Kitchen Bot Webhook
Route::post('/telegram/kitchen/webhook', [\App\Http\Controllers\KitchenBotController::class, 'handleWebhook'])
    ->middleware('verify.telegram.webhook:kitchen')
    ->name('telegram.kitchen.webhook');

// ============================================================
// GetYourGuide Supplier API Endpoints
// All endpoints protected by HTTP Basic Auth (gyg.auth middleware)
// Per GYG spec: always return HTTP 200, errors use JSON error structure
// ============================================================
Route::prefix('gyg/1')->middleware('gyg.auth')->group(function () {
    Route::get('/get-availabilities/',  [\App\Http\Controllers\GygController::class, 'getAvailabilities']);
    Route::post('/reserve/',            [\App\Http\Controllers\GygController::class, 'reserve']);
    Route::post('/cancel-reservation/', [\App\Http\Controllers\GygController::class, 'cancelReservation']);
    Route::post('/book/',               [\App\Http\Controllers\GygController::class, 'book']);
    Route::post('/cancel-booking/',     [\App\Http\Controllers\GygController::class, 'cancelBooking']);
    Route::post('/notify/',             [\App\Http\Controllers\GygController::class, 'notify']);
});

// ============================================================
// Internal Telegram Bot Proxy API
// Cross-app bot operations. Token never leaves this server.
// Auth: X-Service-Key header (per-app key with slug+action allowlists)
// ============================================================
Route::prefix('internal/bots/{slug}')
    ->middleware(['service.key', 'throttle:60,1'])
    ->group(function () {
        Route::post('/send-message', [\App\Http\Controllers\Api\InternalBotController::class, 'sendMessage'])->name('internal.bots.send-message');
        Route::post('/send-photo', [\App\Http\Controllers\Api\InternalBotController::class, 'sendPhoto'])->name('internal.bots.send-photo');
        Route::get('/get-me', [\App\Http\Controllers\Api\InternalBotController::class, 'getMe'])->name('internal.bots.get-me');
        Route::get('/webhook-info', [\App\Http\Controllers\Api\InternalBotController::class, 'webhookInfo'])->name('internal.bots.webhook-info');
        Route::post('/set-webhook', [\App\Http\Controllers\Api\InternalBotController::class, 'setWebhook'])->name('internal.bots.set-webhook');
        Route::post('/delete-webhook', [\App\Http\Controllers\Api\InternalBotController::class, 'deleteWebhook'])->name('internal.bots.delete-webhook');
    });
