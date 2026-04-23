<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| GetYourGuide Supplier API Routes
|--------------------------------------------------------------------------
| Registered WITHOUT /api prefix so GYG can call:
|   GET  /1/get-availabilities/
|   POST /1/reserve/
|   POST /1/cancel-reservation/
|   POST /1/book/
|   POST /1/cancel-booking/
|   POST /1/notify/
|
| All endpoints are protected by HTTP Basic Auth (gyg.auth middleware).
| Per GYG spec: always return HTTP 200; errors use JSON error structure.
|--------------------------------------------------------------------------
*/

Route::prefix('1')->middleware('gyg.auth')->group(function () {
    Route::get('/get-availabilities/',  [\App\Http\Controllers\GygController::class, 'getAvailabilities']);
    Route::post('/reserve/',            [\App\Http\Controllers\GygController::class, 'reserve']);
    Route::post('/cancel-reservation/', [\App\Http\Controllers\GygController::class, 'cancelReservation']);
    Route::post('/book/',               [\App\Http\Controllers\GygController::class, 'book']);
    Route::post('/cancel-booking/',     [\App\Http\Controllers\GygController::class, 'cancelBooking']);
    Route::post('/notify/',             [\App\Http\Controllers\GygController::class, 'notify']);
});
