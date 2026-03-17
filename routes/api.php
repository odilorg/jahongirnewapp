<?php

use Illuminate\Http\Request;
use App\Filament\Pages\Availability;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TelegramController;
use App\Http\Controllers\Api\AiInstructionController;
use App\Http\Controllers\Api\SysInstructionController;
use App\Http\Controllers\TelegramDriverGuideSignUpController;
use App\Http\Controllers\WebhookController;


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

Route::post('/telegram/webhook', [TelegramController::class, 'handleWebhook']);
Route::post('/telegram/driver_guide_signup', [TelegramDriverGuideSignUpController::class, 'handleWebhook']);


Route::post('/webhook/tour-booking', [WebhookController::class, 'handleTourBooking']);


// Telegram Bot Availability Routes
Route::post('/telegram/bot/webhook', [\App\Http\Controllers\TelegramWebhookController::class, 'handle'])->name('telegram.webhook');
Route::post('/telegram/bot/set-webhook', [\App\Http\Controllers\TelegramWebhookController::class, 'setWebhook'])->middleware('auth:sanctum');
Route::get('/telegram/bot/webhook-info', [\App\Http\Controllers\TelegramWebhookController::class, 'getWebhookInfo'])->middleware('auth:sanctum');

// Booking Bot Webhook
Route::post('/booking/bot/webhook', [\App\Http\Controllers\BookingWebhookController::class, 'handle'])->name('booking.bot.webhook');

// Telegram POS Bot Routes
Route::post('/telegram/pos/webhook', [\App\Http\Controllers\TelegramPosController::class, 'handleWebhook'])
    ->middleware(\App\Http\Middleware\ValidateTelegramRequest::class)
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
    ->middleware('verify.telegram.webhook')
    ->name('telegram.cashier.webhook');

Route::post("/telegram/owner/webhook", [\App\Http\Controllers\OwnerBotController::class, "handleWebhook"])
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

    return response()->json([
        'status'      => 'ok',
        'sha'         => $sha,
        'tag'         => $tag,
        'deployed_at' => $deployedAt,
        'php'         => PHP_VERSION,
        'laravel'     => app()->version(),
    ]);
})->name('healthz');

// Beds24 token health check
Route::get('/beds24/health', function () {
    $service = app(\App\Services\Beds24BookingService::class);
    return response()->json($service->getTokenStatus());
})->name('beds24.health');
// Housekeeping Bot Webhook
Route::post('/telegram/housekeeping/webhook', [\App\Http\Controllers\HousekeepingBotController::class, 'handleWebhook'])
    ->name('telegram.housekeeping.webhook');

// Kitchen Bot Webhook
Route::post('/telegram/kitchen/webhook', [\App\Http\Controllers\KitchenBotController::class, 'handleWebhook'])
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
