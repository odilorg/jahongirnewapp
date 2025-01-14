<?php

use App\Models\Chat;
use App\Jobs\TestJob;
use Illuminate\Http\Request;
use App\Models\ScheduledMessage;
use Illuminate\Support\Facades\Log;
use App\Jobs\SendTelegramMessageJob;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

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

Route::post('/webhook/bookings', function (Request $request) {
    try {
        // Validate the incoming request data
        $data = $request->validate([
            'status' => 'required|string',
            'subStatus' => 'nullable|string',
            'arrival' => 'required|date',
            'departure' => 'required|date',
            'numAdult' => 'required|integer',
            'numChild' => 'required|integer',
            'cancelTime' => 'nullable|string',
            'price' => 'required|numeric',
            'deposit' => 'required|numeric',
            'bookingId' => 'required|integer',
            'invoiceeId' => 'required|integer',
            'description' => 'nullable|string',
            'qty' => 'required|integer',
            'amount' => 'required|numeric',
            'vatRate' => 'required|numeric',
            'createdBy' => 'required|integer',
            'tax' => 'required|numeric',
        ]);

        // Prepare the content to save
        $content = json_encode($data, JSON_PRETTY_PRINT);

        // Define the storage path and filename
        $filename = 'bookings/booking_' . now()->format('Ymd_His') . '.txt';

        // Save the content to a file in the storage folder
        Storage::put($filename, $content);

        // Return a success response
        return response()->json(['message' => 'Webhook data saved successfully!', 'file' => $filename], 200);
    } catch (\Exception $e) {
        // Log the error for troubleshooting
        Log::error('Webhook processing failed: ' . $e->getMessage());

        // Return a 200 response to prevent retries, with an error message
        return response()->json(['message' => 'Webhook processing failed, but acknowledged to prevent retries.'], 200);
    }
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
