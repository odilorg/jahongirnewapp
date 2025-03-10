<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\Guide;
use App\Models\Tour;
use App\Models\Driver;
use App\Models\TelegramConversation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class TelegramController extends Controller
{
    protected $authorizedChatId = '38738713'; // or remove if open to all
    protected $botToken;
    protected $conversationTimeout = 15; // in minutes

    public function __construct()
    {
        $this->botToken = config('services.telegram_bot.token');
    }

    /**
     * The webhook endpoint that Telegram calls.
     */
    public function handleWebhook(Request $request)
    {
        // If it’s a callback from an inline keyboard:
        if ($callback = $request->input('callback_query')) {
            return $this->handleCallbackQuery($callback);
        }
        // Otherwise, treat it as a normal message:
        return $this->processMessage($request);
    }

    /**
     * 1) Process typed messages (or recognized commands) from user.
     */
    protected function processMessage(Request $request)
    {
        $message = $request->input('message');
        if (!$message) {
            Log::error("No 'message' in webhook payload.");
            return response('OK');
        }

        $chatId = $message['chat']['id'] ?? null;
        $text   = trim($message['text'] ?? '');

        // OPTIONAL: If you want only one user to have full access
        if ($chatId != $this->authorizedChatId) {
            Log::warning("Unauthorized chat ID: $chatId");
            return response('OK');
        }

        // Basic text message routing
        switch (mb_strtolower($text)) {
            case '/start':
            case '/menu':
                // Show the big "reply keyboard" at the bottom
                return $this->showReplyKeyboardMenu($chatId);

            case 'create booking':
                return $this->startCreateBookingFlow($chatId);

            case 'update booking':
                return $this->showAllBookingsToPickUpdate($chatId);

            case 'delete booking':
                return $this->showAllBookingsToPickDelete($chatId);

            case 'list bookings':
                return $this->listBookings($chatId);

            default:
                // If user is in a conversation flow, handle typed input there
                $conversation = $this->getActiveConversation($chatId);
                if ($conversation) {
                    $flowType = $conversation->data['flow_type'] ?? '';
                    if ($flowType === 'create') {
                        return $this->handleCreateFlow($conversation, $text, false);
                    } elseif ($flowType === 'update') {
                        return $this->handleUpdateFlow($conversation, $text, false);
                    }
                }

                // Unrecognized, prompt them or show the keyboard again
                $this->sendTelegramMessage($chatId, "Not sure what you mean. Please use the menu buttons below.");
                return $this->showReplyKeyboardMenu($chatId);
        }
    }

    /**
     * 2) Handle inline keyboard button presses.
     */
    protected function handleCallbackQuery(array $callback)
    {
        $chatId       = $callback['message']['chat']['id'] ?? null;
        $callbackData = $callback['data'] ?? '';
        $callbackId   = $callback['id'];

        // Acknowledge callback (avoid repeat calls)
        try {
            $this->answerCallbackQuery($callbackId);
        } catch (\Exception $e) {
            Log::warning("Failed to answerCallbackQuery: " . $e->getMessage());
        }

        // If unauthorized
        if ($chatId != $this->authorizedChatId) {
            Log::warning("Unauthorized callback from chat ID: $chatId");
            return response('OK');
        }

        // Check if user is in a conversation (create/update flow)
        $conversation = $this->getActiveConversation($chatId);
        if ($conversation) {
            $flowType = $conversation->data['flow_type'] ?? '';
            if ($flowType === 'create') {
                return $this->handleCreateFlow($conversation, $callbackData, true);
            } elseif ($flowType === 'update') {
                return $this->handleUpdateFlow($conversation, $callbackData, true);
            }
        }

        // If no active conversation, handle top-level callback commands
        // e.g. if the user tapped "update_id:XX" for picking a booking
        if (Str::startsWith($callbackData, 'update_id:')) {
            $bookingId = (int) Str::after($callbackData, 'update_id:');
            return $this->startUpdateFlowWithBooking($chatId, $bookingId);
        }
        if (Str::startsWith($callbackData, 'delete_id:')) {
            $bookingId = (int) Str::after($callbackData, 'delete_id:');
            return $this->confirmDeleteBooking($chatId, $bookingId);
        }
        if (Str::startsWith($callbackData, 'delete_confirm:')) {
            $bookingId = (int) Str::after($callbackData, 'delete_confirm:');
            return $this->performDeleteBooking($chatId, $bookingId);
        }
        if (Str::startsWith($callbackData, 'delete_cancel:')) {
            // user canceled
            return $this->sendTelegramMessage($chatId, "Deletion canceled.");
        }

        // else not recognized
        $this->sendTelegramMessage($chatId, "Callback not recognized: {$callbackData}");
        return response('OK');
    }

    /* ------------------------------------------------------------------
     * MENU: Big Reply Keyboard
     * ------------------------------------------------------------------ */

    /**
     * Show a persistent "reply keyboard" with 4 main buttons:
     * - Create Booking, Update Booking, List Bookings, Delete Booking
     */
    protected function showReplyKeyboardMenu($chatId)
    {
        $keyboard = [
            ['Create Booking', 'Update Booking'],
            ['List Bookings',  'Delete Booking'],
        ];

        $replyMarkup = [
            'keyboard'          => $keyboard,
            'resize_keyboard'   => true,
            'one_time_keyboard' => false,
        ];

        $payload = [
            'chat_id'      => $chatId,
            'text'         => "Select an action:",
            'reply_markup' => json_encode($replyMarkup),
        ];

        $this->sendRawTelegramRequest('sendMessage', $payload);
        return response('OK');
    }

    /* ------------------------------------------------------------------
     * CREATE FLOW (Multi-Step)
     * ------------------------------------------------------------------ */

    protected function startCreateBookingFlow($chatId)
    {
        // See if there's an existing create conversation
        $conversation = $this->getActiveConversation($chatId);
        if ($conversation && ($conversation->data['flow_type'] ?? '') === 'create' && $conversation->step > 0) {
            // Already in a create flow
            $keyboard = [
                [
                    ['text' => 'Resume', 'callback_data' => 'resume_create'],
                    ['text' => 'Cancel', 'callback_data' => 'cancel_create'],
                ],
            ];
            $payload = [
                'chat_id'      => $chatId,
                'text'         => "You have an active create flow. Resume or Cancel?",
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
            ];
            $this->sendRawTelegramRequest('sendMessage', $payload);
            return response('OK');
        }

        // Otherwise start fresh
        $conversation = TelegramConversation::updateOrCreate(
            ['chat_id' => $chatId],
            [
                'step' => 1,
                'data' => ['flow_type' => 'create'],
                'updated_at' => now(),
            ]
        );

        return $this->askForGuest($chatId);
    }

    protected function handleCreateFlow(TelegramConversation $conversation, string $input, bool $isCallback)
    {
        // (Same multi-step logic you had: steps 1..n)
        // For brevity, we’ll show short versions. E.g. step 1 asks user to pick a guest from inline buttons, etc.
        // ...
        // CASES: see previous examples for the multi-step approach
        return response('OK');
    }

    // Example of step 1 in create flow
    protected function askForGuest($chatId)
    {
        $guests = Guest::take(5)->get();
        if ($guests->isEmpty()) {
            $this->sendTelegramMessage($chatId, "No guests found. Please add guests first.");
            return response('OK');
        }

        $keyboard = [];
        foreach ($guests as $guest) {
            $keyboard[] = [[
                'text'          => $guest->full_name,
                'callback_data' => "guest_id:{$guest->id}",
            ]];
        }
        $keyboard[] = [[ 'text'=>'Cancel', 'callback_data'=>'cancel_create' ]];

        $payload = [
            'chat_id'      => $chatId,
            'text'         => "Step 1: Select a Guest (top 5) or Cancel:",
            'reply_markup' => json_encode(['inline_keyboard'=>$keyboard]),
        ];
        $this->sendRawTelegramRequest('sendMessage', $payload);
        return response('OK');
    }

    // Example final step: create DB record
    protected function createBookingRecord(TelegramConversation $conversation)
    {
        $data = $conversation->data ?? [];
        try {
            $booking = Booking::create([
                'guest_id'                => $data['guest_id'] ?? null,
                'group_name'              => $data['group_name'] ?? '',
                'booking_start_date_time' => $data['booking_start_date_time'] ?? null,
                'tour_id'                 => $data['tour_id'] ?? null,
                'guide_id'                => $data['guide_id'] ?? null,
                'driver_id'               => $data['driver_id'] ?? null,
                'pickup_location'         => $data['pickup_location'] ?? '',
                'dropoff_location'        => $data['dropoff_location'] ?? '',
                'booking_status'          => $data['booking_status'] ?? 'pending',
                'booking_source'          => $data['booking_source'] ?? 'other',
                'special_requests'        => $data['special_requests'] ?? '',
            ]);
            $this->endConversation($conversation);
            $this->sendTelegramMessage($conversation->chat_id,
                "Booking created! ID: {$booking->id}");
        } catch (\Exception $e) {
            Log::error("Error creating booking: " . $e->getMessage());
            $this->sendTelegramMessage($conversation->chat_id,
                "Error creating booking. Try again later.");
        }
        return response('OK');
    }

    /* ------------------------------------------------------------------
     * UPDATE FLOW
     * ------------------------------------------------------------------ */

    /**
     * Instead of typing an ID, we list bookings with inline buttons.
     */
    protected function showAllBookingsToPickUpdate($chatId)
    {
        $bookings = Booking::orderBy('id','desc')->take(5)->get();
        if ($bookings->isEmpty()) {
            $this->sendTelegramMessage($chatId, "No bookings found to update.");
            return response('OK');
        }

        $keyboard = [];
        foreach ($bookings as $booking) {
            $keyboard[] = [[
                'text'          => "ID:{$booking->id} | {$booking->group_name}",
                'callback_data' => "update_id:{$booking->id}",
            ]];
        }
        $payload = [
            'chat_id'      => $chatId,
            'text'         => "Pick a booking to update:",
            'reply_markup' => json_encode(['inline_keyboard'=>$keyboard]),
        ];
        $this->sendRawTelegramRequest('sendMessage', $payload);
        return response('OK');
    }

    /**
     * Once user picks a booking, start the multi-step update flow.
     */
    protected function startUpdateFlowWithBooking($chatId, $bookingId)
    {
        $booking = Booking::find($bookingId);
        if (!$booking) {
            $this->sendTelegramMessage($chatId, "Booking not found.");
            return response('OK');
        }

        $conversation = TelegramConversation::updateOrCreate(
            ['chat_id' => $chatId],
            [
                'step'      => 2, // skip step 1 (picking ID)
                'data'      => [
                    'flow_type'  => 'update',
                    'booking_id' => $booking->id,
                    'changes'    => [],
                ],
                'updated_at'=> now(),
            ]
        );

        // Show a list of fields to update
        return $this->showUpdateFieldMenu($conversation, $booking,
            "Updating Booking #{$booking->id}. Pick a field:");
    }

    protected function handleUpdateFlow(TelegramConversation $conversation, string $input, bool $isCallback)
    {
        // multi-step update logic
        // e.g. step 2 => pick field, step 3 => provide new value, step 4 => confirm
        // See the earlier code snippet on how to handle "statuspick:", "sourcepick:", etc.
        return response('OK');
    }

    // Example: Show field selection inline keyboard
    protected function showUpdateFieldMenu(TelegramConversation $conversation, Booking $booking, $headerText=null)
    {
        $headerText = $headerText ?: "Which field do you want to update?";

        $keyboard = [
            [
                ['text'=>'Date/Time','callback_data'=>'update_field:booking_start_date_time'],
                ['text'=>'Guest',    'callback_data'=>'update_field:guest_id'],
            ],
            [
                ['text'=>'Tour',     'callback_data'=>'update_field:tour_id'],
                ['text'=>'Guide',    'callback_data'=>'update_field:guide_id'],
            ],
            [
                ['text'=>'Driver',   'callback_data'=>'update_field:driver_id'],
                ['text'=>'Pickup',   'callback_data'=>'update_field:pickup_location'],
            ],
            [
                ['text'=>'Dropoff',  'callback_data'=>'update_field:dropoff_location'],
                ['text'=>'Status',   'callback_data'=>'update_field:booking_status'],
            ],
            [
                ['text'=>'Source',   'callback_data'=>'update_field:booking_source'],
                ['text'=>'Requests', 'callback_data'=>'update_field:special_requests'],
            ],
            [
                ['text'=>'Finish','callback_data'=>'finish_update'],
            ],
        ];

        $payload = [
            'chat_id'      => $conversation->chat_id,
            'text'         => $headerText,
            'reply_markup' => json_encode(['inline_keyboard'=>$keyboard]),
        ];
        $this->sendRawTelegramRequest('sendMessage', $payload);
        return response('OK');
    }

    /* ------------------------------------------------------------------
     * DELETE FLOW
     * ------------------------------------------------------------------ */

    protected function showAllBookingsToPickDelete($chatId)
    {
        $bookings = Booking::orderBy('id','desc')->take(5)->get();
        if ($bookings->isEmpty()) {
            $this->sendTelegramMessage($chatId, "No bookings found to delete.");
            return response('OK');
        }

        $keyboard = [];
        foreach ($bookings as $b) {
            $keyboard[] = [[
                'text'=>"ID:{$b->id} | {$b->group_name}",
                'callback_data'=>"delete_id:{$b->id}",
            ]];
        }

        $payload = [
            'chat_id'=>$chatId,
            'text'=>"Pick a booking to delete:",
            'reply_markup'=>json_encode(['inline_keyboard'=>$keyboard]),
        ];
        $this->sendRawTelegramRequest('sendMessage', $payload);
        return response('OK');
    }

    protected function confirmDeleteBooking($chatId, $bookingId)
    {
        $booking = Booking::find($bookingId);
        if (!$booking) {
            $this->sendTelegramMessage($chatId, "Booking not found or already deleted.");
            return response('OK');
        }

        $text = "Are you sure you want to DELETE booking #{$booking->id}?\n"
              . "Guest: {$booking->group_name}\nTour: ".($booking->tour->title ?? 'N/A');

        $keyboard = [
            [
                ['text'=>'Yes, Delete','callback_data'=>"delete_confirm:{$booking->id}"],
                ['text'=>'No, Cancel','callback_data'=>"delete_cancel:0"],
            ]
        ];

        $payload = [
            'chat_id'=>$chatId,
            'text'=>$text,
            'reply_markup'=>json_encode(['inline_keyboard'=>$keyboard]),
        ];
        $this->sendRawTelegramRequest('sendMessage', $payload);
        return response('OK');
    }

    protected function performDeleteBooking($chatId, $bookingId)
    {
        $booking = Booking::find($bookingId);
        if (!$booking) {
            $this->sendTelegramMessage($chatId, "Booking not found or already deleted.");
            return response('OK');
        }

        try {
            $booking->delete();
            $this->sendTelegramMessage($chatId, "Booking #{$bookingId} deleted successfully.");
        } catch (\Exception $e) {
            Log::error("Error deleting booking #{$bookingId}: " . $e->getMessage());
            $this->sendTelegramMessage($chatId, "Error deleting booking. Please try again.");
        }

        return response('OK');
    }

    /* ------------------------------------------------------------------
     * LIST FLOW
     * ------------------------------------------------------------------ */

    protected function listBookings($chatId)
    {
        $bookings = Booking::orderBy('booking_start_date_time','asc')->take(10)->get();
        if ($bookings->isEmpty()) {
            $this->sendTelegramMessage($chatId, "No bookings found.");
            return response('OK');
        }

        $responseText = "Bookings:\n\n";
        foreach ($bookings as $b) {
            $date = $b->booking_start_date_time
                ? Carbon::parse($b->booking_start_date_time)->format('M j Y H:i')
                : 'N/A';

            $responseText .= "ID: {$b->id}\n"
                           . "Group: {$b->group_name}\n"
                           . "Tour: ".($b->tour->title??'N/A')."\n"
                           . "Date: {$date}\n"
                           . "Status: {$b->booking_status}\n"
                           . "-------------------------\n";
        }

        $this->sendTelegramMessage($chatId, $responseText);
        return response('OK');
    }

    /* ------------------------------------------------------------------
     *  Conversation Helpers
     * ------------------------------------------------------------------ */

    protected function getActiveConversation($chatId)
    {
        $c = TelegramConversation::where('chat_id',$chatId)->first();
        if (!$c) return null;

        // Check for timeout
        if (Carbon::parse($c->updated_at)->diffInMinutes(now()) > $this->conversationTimeout) {
            $c->delete();
            return null;
        }
        return $c;
    }

    protected function endConversation(TelegramConversation $conversation)
    {
        $conversation->delete();
    }

    /**
     * Send a plain text message with the large “reply keyboard” we built.
     */
    protected function sendTelegramMessage($chatId, $text)
    {
        if (!$this->botToken) {
            Log::error("TELEGRAM_BOT_TOKEN not set.");
            return false;
        }

        $url = "https://api.telegram.org/bot{$this->botToken}/sendMessage";
        try {
            $response = Http::post($url, [
                'chat_id' => $chatId,
                'text'    => $text,
            ]);
            if ($response->failed()) {
                Log::error("sendTelegramMessage failed: {$response->body()}");
                return false;
            }
        } catch (\Exception $e) {
            Log::error("sendTelegramMessage exception: " . $e->getMessage());
            return false;
        }
        return true;
    }

    /**
     * Send any payload to Telegram (inline keyboard, reply, etc.).
     */
    protected function sendRawTelegramRequest($method, array $payload)
    {
        if (!$this->botToken) {
            Log::error("TELEGRAM_BOT_TOKEN missing.");
            return false;
        }
        $url = "https://api.telegram.org/bot{$this->botToken}/{$method}";

        try {
            $options = [
                'http' => [
                    'method'  => 'POST',
                    'header'  => "Content-Type: application/json\r\n",
                    'content' => json_encode($payload),
                ]
            ];
            $context = stream_context_create($options);
            $res     = file_get_contents($url, false, $context);

            if ($res === false) {
                Log::error("sendRawTelegramRequest failed: $method");
                return false;
            }
        } catch (\Exception $e) {
            Log::error("sendRawTelegramRequest exception: " . $e->getMessage());
            return false;
        }
        return true;
    }

    protected function answerCallbackQuery($callbackId, $text='OK', $showAlert=false)
    {
        $payload = [
            'callback_query_id' => $callbackId,
            'text'              => $text,
            'show_alert'        => $showAlert
        ];
        $this->sendRawTelegramRequest('answerCallbackQuery', $payload);
    }
}
