<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessBookingMessage;
use App\Support\BookingBot\LogSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BookingWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        Log::info('Booking Bot Webhook Received', LogSanitizer::context(['data' => $request->all()]));
        
        $update = $request->all();
        
        if (empty($update) || !isset($update['update_id'])) {
            return response()->json(['error' => 'Invalid webhook data'], 400);
        }
        
        // Dispatch to job queue
        ProcessBookingMessage::dispatch($update);
        
        return response()->json(['ok' => true]);
    }
}
