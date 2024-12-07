<?php

use App\Models\Chat;
use App\Jobs\TestJob;
use App\Models\ScheduledMessage;
use Illuminate\Support\Facades\Log;
use App\Jobs\SendTelegramMessageJob;
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

Route::get('/test-job', function () {
    TestJob::dispatch();
    return 'Test job dispatched!';
});

Route::get('/', function () {
    return redirect()->route('filament.admin.auth.login');
});

// Route::get('/dispatch-job', function () {
//     $message = \App\Models\ScheduledMessage::first(); // Adjust as needed
//     \App\Jobs\SendTelegramMessageJob::dispatch($message);

//     return 'Job dispatched!';
// });

// Route::get('/dispatch-job', function () {
//     // Fetch or create a message
//     $message = ScheduledMessage::first(); // Adjust as needed
    
//     // Fetch or create a chat
//     $chat = $message->chat->chat_id; // Adjust as needed
//  //dd($chat);
//     if ($message && $chat) {
//         // Dispatch the job with both arguments
//         SendTelegramMessageJob::dispatch($message, $chat);

//         return 'Job dispatched!';
//     }

//     return 'Failed to dispatch job.';
// });
