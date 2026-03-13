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
    ->name('telegram.cashier.webhook');

Route::post("/telegram/owner/webhook", [\App\Http\Controllers\OwnerBotController::class, "handleWebhook"])
    ->name("telegram.owner.webhook");


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
