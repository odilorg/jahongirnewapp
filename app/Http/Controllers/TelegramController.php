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
    protected $authorizedChatId = '38738713';
    protected $botToken;

    // Timeout in minutes for an inactive conversation
    protected $conversationTimeout = 15;

    public function __construct()
    {
        $this->botToken = config('services.telegram_bot.token');
    }

    /**
     * Webhook endpoint for Telegram.
     */
    public function handleWebhook(Request $request)
    {
        // If it’s a callback from inline keyboards:
        if ($callback = $request->input('callback_query')) {
            return $this->handleCallbackQuery($callback);
        }

        // Otherwise, treat as normal message:
        return $this->processMessage($request);
    }

    /**
     * 1) Handle typed messages/commands
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

        if ($chatId != $this->authorizedChatId) {
            Log::warning("Unauthorized chat ID: {$chatId}");
            return response('OK');
        }

        // Check for slash commands
        if (Str::startsWith($text, '/create')) {
            return $this->startCreateBookingFlow($chatId);
        }

        // If we’re in a conversation flow, handle that typed input
        $conversation = $this->getActiveConversation($chatId);
        if ($conversation && $conversation->step > 0) {
            // We’re in the “create booking” flow, handle typed input
            return $this->handleCreateFlow($conversation, $text, false);
        }

        // Otherwise, unrecognized command
        $this->sendTelegramMessage($chatId, "Command not recognized. Type /create to begin booking.");
        return response('OK');
    }

    /**
     * 2) Handle inline keyboard button presses
     */
    protected function handleCallbackQuery(array $callback)
    {
        $chatId       = $callback['message']['chat']['id'] ?? null;
        $callbackData = $callback['data'] ?? '';
        $callbackId   = $callback['id'];

        // Acknowledge callback
        $this->answerCallbackQuery($callbackId);

        if ($chatId != $this->authorizedChatId) {
            Log::warning("Unauthorized callback from chat ID: {$chatId}");
            return response('OK');
        }

        // Find active conversation
        $conversation = $this->getActiveConversation($chatId);
        if ($conversation && $conversation->step > 0) {
            // We are in create booking flow
            return $this->handleCreateFlow($conversation, $callbackData, true);
        }

        // Otherwise, top-level callback
        switch ($callbackData) {
            default:
                $this->sendTelegramMessage($chatId, "Callback not recognized: {$callbackData}");
                break;
        }

        return response('OK');
    }

    /* ---------------------------------------------------------------------
     * Multi-Step Create Booking Flow
     * --------------------------------------------------------------------- */

    /**
     * Initiate the booking creation flow.
     * - If a conversation is already in progress, ask user to resume or cancel.
     */
    protected function startCreateBookingFlow($chatId)
    {
        $conversation = $this->getActiveConversation($chatId);

        if ($conversation && $conversation->step > 0) {
            // Already in a flow. Ask to resume or cancel
            $keyboard = [
                [
                    ['text' => 'Resume', 'callback_data' => 'resume_create'],
                    ['text' => 'Cancel', 'callback_data' => 'cancel_create'],
                ],
            ];
            $payload = [
                'chat_id'      => $chatId,
                'text'         => "You already have an active booking creation. Resume or cancel?",
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
            ];
            $this->sendRawTelegramRequest('sendMessage', $payload);
            return response('OK');
        }

        // Otherwise, create a new conversation record
        $conversation = TelegramConversation::updateOrCreate(
            ['chat_id' => $chatId],
            [
                'step'      => 1,
                'data'      => [],
                'updated_at'=> now(),
            ]
        );

        // Step 1: Show guests
        $this->askForGuest($chatId);
        return response('OK');
    }

    /**
     * The big state machine for the create booking flow.
     */
    protected function handleCreateFlow(TelegramConversation $conversation, string $input, bool $isCallback)
    {
        // If user tapped "resume_create" or "cancel_create" at the top level:
        if ($input === 'resume_create') {
            // Just re-ask the question for the current step
            return $this->reAskCurrentStep($conversation);
        }
        if ($input === 'cancel_create') {
            $this->endConversation($conversation);
            $this->sendTelegramMessage($conversation->chat_id, "Booking creation canceled.");
            return response('OK');
        }

        // Update last active time
        $conversation->updated_at = now();
        $conversation->save();

        // Switch by step
        switch ($conversation->step) {
            case 1:
                // We’re waiting for the user to pick a guest
                if ($isCallback && Str::startsWith($input, 'guest_id:')) {
                    $guestId = (int) Str::after($input, 'guest_id:');
                    $this->saveConversationData($conversation, ['guest_id' => $guestId]);

                    // Next step
                    $conversation->step = 2;
                    $conversation->save();

                    return $this->askForStartDateTime($conversation);
                }
                // If user typed something instead of pressing a button:
                $this->sendTelegramMessage($conversation->chat_id, "Please pick a guest by tapping a button.");
                break;

            case 2:
                // We’re waiting for date/time typed input
                if (!$isCallback) {
                    try {
                        $dateTime = Carbon::parse($input);
                        $this->saveConversationData($conversation, [
                            'booking_start_date_time' => $dateTime->toDateTimeString()
                        ]);

                        // Next step
                        $conversation->step = 3;
                        $conversation->save();

                        return $this->askForTour($conversation);
                    } catch (\Exception $e) {
                        $this->sendTelegramMessage($conversation->chat_id, "Invalid date/time. Try again (format: 2025-03-15 09:00).");
                    }
                } else {
                    $this->sendTelegramMessage($conversation->chat_id, "Please type a date/time, e.g. 2025-03-15 09:00.");
                }
                break;

            case 3:
                // We’re waiting for the user to pick a Tour
                if ($isCallback && Str::startsWith($input, 'tour_id:')) {
                    $tourId = (int) Str::after($input, 'tour_id:');
                    $this->saveConversationData($conversation, ['tour_id' => $tourId]);

                    // Next step
                    $conversation->step = 4;
                    $conversation->save();

                    return $this->askForGuide($conversation);
                }
                $this->sendTelegramMessage($conversation->chat_id, "Please pick a Tour from the buttons.");
                break;

            case 4:
                // We’re waiting for the user to pick a Guide
                if ($isCallback && Str::startsWith($input, 'guide_id:')) {
                    $guideId = (int) Str::after($input, 'guide_id:');
                    $this->saveConversationData($conversation, ['guide_id' => $guideId === 0 ? null : $guideId]);

                    // Next step
                    $conversation->step = 5;
                    $conversation->save();

                    return $this->askForDriver($conversation);
                }
                $this->sendTelegramMessage($conversation->chat_id, "Please pick a Guide button (or No guide).");
                break;

            case 5:
                // We’re waiting for user to pick a Driver
                if ($isCallback && Str::startsWith($input, 'driver_id:')) {
                    $driverId = (int) Str::after($input, 'driver_id:');
                    $this->saveConversationData($conversation, ['driver_id' => $driverId === 0 ? null : $driverId]);

                    // Next step
                    $conversation->step = 6;
                    $conversation->save();

                    // Ask for pickup location (typed)
                    $this->sendTelegramMessage($conversation->chat_id, "Step 6: Please type the Pickup Location:");
                    return response('OK');
                }
                $this->sendTelegramMessage($conversation->chat_id, "Please pick a Driver button (or No driver).");
                break;

            case 6:
                // We expect typed text for pickup_location
                if (!$isCallback) {
                    $this->saveConversationData($conversation, ['pickup_location' => $input]);

                    $conversation->step = 7;
                    $conversation->save();

                    $this->sendTelegramMessage($conversation->chat_id, "Step 7: Please type the Dropoff Location:");
                    return response('OK');
                }
                $this->sendTelegramMessage($conversation->chat_id, "Please type the Pickup Location as text.");
                break;

            case 7:
                // We expect typed text for dropoff_location
                if (!$isCallback) {
                    $this->saveConversationData($conversation, ['dropoff_location' => $input]);

                    $conversation->step = 8;
                    $conversation->save();

                    return $this->askForBookingStatus($conversation);
                }
                $this->sendTelegramMessage($conversation->chat_id, "Please type the Dropoff Location as text.");
                break;

            case 8:
                // We expect user to pick booking_status from inline keyboard
                if ($isCallback && Str::starts_with($input, 'status:')) {
                    $status = Str::after($input, 'status:');
                    $this->saveConversationData($conversation, ['booking_status' => $status]);

                    $conversation->step = 9;
                    $conversation->save();

                    return $this->askForBookingSource($conversation);
                }
                $this->sendTelegramMessage($conversation->chat_id, "Please pick a Booking Status from buttons.");
                break;

            case 9:
                // We expect user to pick booking_source from inline keyboard
                if ($isCallback && Str::starts_with($input, 'source:')) {
                    $source = Str::after($input, 'source:');
                    $this->saveConversationData($conversation, ['booking_source' => $source]);

                    $conversation->step = 10;
                    $conversation->save();

                    $this->sendTelegramMessage($conversation->chat_id, "Step 10: Any special requests? Type 'none' if not.");
                    return response('OK');
                }
                $this->sendTelegramMessage($conversation->chat_id, "Please pick a Booking Source from buttons.");
                break;

            case 10:
                // We expect typed text for special_requests
                if (!$isCallback) {
                    $this->saveConversationData($conversation, ['special_requests' => $input]);

                    $conversation->step = 11;
                    $conversation->save();

                    return $this->askForConfirmation($conversation);
                }
                $this->sendTelegramMessage($conversation->chat_id, "Please type your Special Requests (or 'none').");
                break;

            case 11:
                // Waiting for user to confirm or cancel
                if ($input === 'confirm_create') {
                    return $this->createBookingRecord($conversation);
                }
                $this->sendTelegramMessage($conversation->chat_id, "Please confirm or cancel.");
                break;

            default:
                $this->sendTelegramMessage($conversation->chat_id, "Invalid step. Try /create again.");
                break;
        }

        return response('OK');
    }

    /* ---------------------------------------------------------------------
     *  Steps: Ask Methods
     * --------------------------------------------------------------------- */

    protected function askForGuest($chatId)
    {
        // Show a list of first 5 guests
        $guests = Guest::take(5)->get();
        $keyboard = [];
        foreach ($guests as $guest) {
            $keyboard[] = [[
                'text'          => $guest->full_name,
                'callback_data' => "guest_id:{$guest->id}",
            ]];
        }
        $keyboard[] = [[ 'text' => 'Cancel', 'callback_data' => 'cancel_create' ]];

        $payload = [
            'chat_id'      => $chatId,
            'text'         => "Step 1: Select a Guest (showing first 5) or Cancel:",
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ];
        $this->sendRawTelegramRequest('sendMessage', $payload);
    }

    protected function askForStartDateTime(TelegramConversation $conversation)
    {
        $this->sendTelegramMessage($conversation->chat_id, "Step 2: Please type the Tour Start Date & Time (YYYY-MM-DD HH:MM).");
        return response('OK');
    }

    protected function askForTour(TelegramConversation $conversation)
    {
        $tours = Tour::take(5)->get();
        $keyboard = [];
        foreach ($tours as $tour) {
            $keyboard[] = [[
                'text'          => $tour->title,
                'callback_data' => "tour_id:{$tour->id}",
            ]];
        }
        $keyboard[] = [[ 'text' => 'Cancel', 'callback_data' => 'cancel_create' ]];

        $payload = [
            'chat_id'      => $conversation->chat_id,
            'text'         => "Step 3: Select a Tour (showing first 5).",
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ];
        $this->sendRawTelegramRequest('sendMessage', $payload);
        return response('OK');
    }

    protected function askForGuide(TelegramConversation $conversation)
    {
        $guides = Guide::take(5)->get();
        $keyboard = [];

        $keyboard[] = [[
            'text'          => 'No guide',
            'callback_data' => "guide_id:0",
        ]];

        foreach ($guides as $guide) {
            $keyboard[] = [[
                'text'          => $guide->full_name,
                'callback_data' => "guide_id:{$guide->id}",
            ]];
        }
        $keyboard[] = [[ 'text' => 'Cancel', 'callback_data' => 'cancel_create' ]];

        $payload = [
            'chat_id'      => $conversation->chat_id,
            'text'         => "Step 4: Select a Guide (or choose No guide).",
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ];
        $this->sendRawTelegramRequest('sendMessage', $payload);
        return response('OK');
    }

    protected function askForDriver(TelegramConversation $conversation)
    {
        $drivers = Driver::take(5)->get();
        $keyboard = [];

        $keyboard[] = [[
            'text'          => 'No driver',
            'callback_data' => 'driver_id:0',
        ]];

        foreach ($drivers as $driver) {
            $keyboard[] = [[
                'text'          => $driver->full_name,
                'callback_data' => "driver_id:{$driver->id}",
            ]];
        }
        $keyboard[] = [[ 'text' => 'Cancel', 'callback_data' => 'cancel_create' ]];

        $payload = [
            'chat_id'      => $conversation->chat_id,
            'text'         => "Step 5: Select a Driver (or choose No driver).",
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ];
        $this->sendRawTelegramRequest('sendMessage', $payload);
        return response('OK');
    }

    protected function askForBookingStatus(TelegramConversation $conversation)
    {
        $keyboard = [
            [['text' => 'Pending',     'callback_data' => 'status:pending']],
            [['text' => 'In Progress', 'callback_data' => 'status:in_progress']],
            [['text' => 'Finished',    'callback_data' => 'status:finished']],
            [['text' => 'Cancel',      'callback_data' => 'cancel_create']],
        ];
        $payload = [
            'chat_id'      => $conversation->chat_id,
            'text'         => "Step 8: Choose Booking Status:",
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ];
        $this->sendRawTelegramRequest('sendMessage', $payload);
        return response('OK');
    }

    protected function askForBookingSource(TelegramConversation $conversation)
    {
        $keyboard = [
            [['text' => 'Viatour',    'callback_data' => 'source:viatour']],
            [['text' => 'GetUrGuide','callback_data' => 'source:geturguide']],
            [['text' => 'Website',   'callback_data' => 'source:website']],
            [['text' => 'Walk In',   'callback_data' => 'source:walkin']],
            [['text' => 'Phone',     'callback_data' => 'source:phone']],
            [['text' => 'Email',     'callback_data' => 'source:email']],
            [['text' => 'Other',     'callback_data' => 'source:other']],
            [['text' => 'Cancel',    'callback_data' => 'cancel_create']],
        ];
        $payload = [
            'chat_id'      => $conversation->chat_id,
            'text'         => "Step 9: Choose Booking Source:",
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ];
        $this->sendRawTelegramRequest('sendMessage', $payload);
        return response('OK');
    }

    protected function askForConfirmation(TelegramConversation $conversation)
    {
        $summary = $this->formatBookingSummary($conversation->data);

        $keyboard = [
            [['text' => 'Confirm', 'callback_data' => 'confirm_create']],
            [['text' => 'Cancel',  'callback_data' => 'cancel_create']],
        ];

        $payload = [
            'chat_id'      => $conversation->chat_id,
            'text'         => "Review your booking:\n\n{$summary}",
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ];
        $this->sendRawTelegramRequest('sendMessage', $payload);
        return response('OK');
    }

    /* ---------------------------------------------------------------------
     * Conversation & Booking Helpers
     * --------------------------------------------------------------------- */

    /**
     * Attempt to load active conversation, checking timeout.
     */
    protected function getActiveConversation($chatId)
    {
        $conversation = TelegramConversation::where('chat_id', $chatId)->first();

        if (!$conversation) {
            return null;
        }

        // Check if timed out
        $lastUpdated = Carbon::parse($conversation->updated_at);
        if ($lastUpdated->diffInMinutes(now()) > $this->conversationTimeout) {
            // Too old, kill it
            $conversation->delete();
            return null;
        }
        return $conversation;
    }

    protected function endConversation(TelegramConversation $conversation)
    {
        $conversation->delete();
    }

    protected function reAskCurrentStep(TelegramConversation $conversation)
    {
        switch ($conversation->step) {
            case 1: return $this->askForGuest($conversation->chat_id);
            case 2: return $this->askForStartDateTime($conversation);
            case 3: return $this->askForTour($conversation);
            case 4: return $this->askForGuide($conversation);
            case 5: return $this->askForDriver($conversation);
            case 6:
                $this->sendTelegramMessage($conversation->chat_id, "Step 6: Please type the Pickup Location:");
                break;
            case 7:
                $this->sendTelegramMessage($conversation->chat_id, "Step 7: Please type the Dropoff Location:");
                break;
            case 8: return $this->askForBookingStatus($conversation);
            case 9: return $this->askForBookingSource($conversation);
            case 10:
                $this->sendTelegramMessage($conversation->chat_id, "Step 10: Any special requests? Type 'none' if not.");
                break;
            case 11:
                return $this->askForConfirmation($conversation);
        }
        return response('OK');
    }

    protected function saveConversationData(TelegramConversation $conversation, array $newData)
    {
        $data = $conversation->data ?: [];
        foreach ($newData as $k => $v) {
            $data[$k] = $v;
        }
        $conversation->data = $data;
        $conversation->save();
    }

    /**
     * Actually create the Booking in DB after user confirms.
     */
    protected function createBookingRecord(TelegramConversation $conversation)
    {
        $data = $conversation->data ?? [];

        try {
            $booking = Booking::create([
                'guest_id'                => $data['guest_id'] ?? null,
                'booking_start_date_time' => $data['booking_start_date_time'] ?? null,
                'tour_id'                 => $data['tour_id'] ?? null,
                'guide_id'                => $data['guide_id'] ?? null,
                'driver_id'               => $data['driver_id'] ?? null,
                'pickup_location'         => $data['pickup_location'] ?? '',
                'dropoff_location'        => $data['dropoff_location'] ?? '',
                'booking_status'          => $data['booking_status'] ?? 'pending',
                'booking_source'          => $data['booking_source'] ?? 'other',
                'special_requests'        => $data['special_requests'] ?? '',
                // Add other fields as needed
            ]);

            $this->endConversation($conversation);

            $this->sendTelegramMessage($conversation->chat_id, "Booking created successfully! ID: {$booking->id}");
        } catch (\Exception $e) {
            Log::error("Error creating booking: " . $e->getMessage());
            $this->sendTelegramMessage($conversation->chat_id, "Error creating booking. Please try again later.");
        }

        return response('OK');
    }

    protected function formatBookingSummary(array $data)
    {
        $guest = Guest::find($data['guest_id'] ?? null);
        $tour  = Tour::find($data['tour_id'] ?? null);
        $guide = $data['guide_id'] ? Guide::find($data['guide_id']) : null;
        $driver= $data['driver_id']? Driver::find($data['driver_id']) : null;

        $text  = "Guest: ".($guest->full_name ?? 'N/A')."\n";
        $text .= "Start: ".($data['booking_start_date_time'] ?? 'N/A')."\n";
        $text .= "Tour: ".($tour->title ?? 'N/A')."\n";
        $text .= "Guide: ".($guide ? $guide->full_name : 'No guide')."\n";
        $text .= "Driver: ".($driver ? $driver->full_name : 'No driver')."\n";
        $text .= "Pickup: ".($data['pickup_location'] ?? '')."\n";
        $text .= "Dropoff: ".($data['dropoff_location'] ?? '')."\n";
        $text .= "Status: ".($data['booking_status'] ?? '')."\n";
        $text .= "Source: ".($data['booking_source'] ?? '')."\n";
        $text .= "Requests: ".($data['special_requests'] ?? '')."\n";
        return $text;
    }

    /* ---------------------------------------------------------------------
     *  Telegram Utility Methods
     * --------------------------------------------------------------------- */

    protected function sendTelegramMessage($chatId, $text)
    {
        if (!$this->botToken) {
            Log::error("No TELEGRAM_BOT_TOKEN found in config.");
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

    protected function sendRawTelegramRequest($method, array $payload)
    {
        if (!$this->botToken) {
            Log::error("No TELEGRAM_BOT_TOKEN found in config.");
            return false;
        }

        $url = "https://api.telegram.org/bot{$this->botToken}/{$method}";

        try {
            $options = [
                'http' => [
                    'method'  => 'POST',
                    'header'  => "Content-Type: application/json\r\n",
                    'content' => json_encode($payload),
                ],
            ];
            $context = stream_context_create($options);
            $res     = file_get_contents($url, false, $context);

            if ($res === false) {
                Log::error("sendRawTelegramRequest failed to connect: $method");
                return false;
            }
        } catch (\Exception $e) {
            Log::error("sendRawTelegramRequest exception: " . $e->getMessage());
            return false;
        }
        return true;
    }

    protected function answerCallbackQuery($callbackId, $text = 'OK', $showAlert = false)
    {
        $payload = [
            'callback_query_id' => $callbackId,
            'text'              => $text,
            'show_alert'        => $showAlert,
        ];
        $this->sendRawTelegramRequest('answerCallbackQuery', $payload);
    }
}
