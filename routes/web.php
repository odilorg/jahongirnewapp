<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return redirect()->route('filament.admin.auth.login');
});

Route::get('/dispatch-job', function () {
    $message = \App\Models\ScheduledMessage::first(); // Adjust as needed
    \App\Jobs\SendTelegramMessageJob::dispatch($message);

    return 'Job dispatched!';
});
