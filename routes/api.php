<?php

use Illuminate\Http\Request;
use App\Filament\Pages\Availability;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TelegramController;
use App\Http\Controllers\Api\AiInstructionController;
use App\Http\Controllers\Api\SysInstructionController;

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

