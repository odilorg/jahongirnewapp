<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Guest;
use App\Models\Guide;
use App\Models\Tour;
use App\Models\Driver;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class TelegramController extends Controller
{
    protected $authorizedChatId = '38738713';

    /** @var string */
    protected $botToken;

    /**
     * Very simple conversation store (in-memory).
     * In production, store in DB or cache (e.g. Redis).
     */
    protected static $conversationState = [];

    public function __construct()
    {
        // Make sure config/services.php has ['telegram_bot' => ['token' => env('TELEGRAM_BOT_TOKEN')]]
        $this->botToken = config('services.telegram_bot.token');
    }

    /**
     * Webhook endpoint from Telegram.
     */
    public function handleWebhook(Request $request)
    {
        // If it’s a callback from an inline keyboard:
        if ($callback = $request->input('callback_query')) {
            return $this->handleCallbackQuery($callback);
        }
        // Otherwise, treat it as a normal message:
        return $this->processCommand($request);
    }

    /**
     * Handle inline keyboard button presses.
     */
    protected function handleCallbackQuery(array $callback)
    {
        $callbackData = $callback['data'] ?? '';
        $chatId       = $callback['message']['chat']['id'] ?? null;
        $callbackId   = $callback['id'];

        // Acknowledge we received the callback so Telegram doesn’t keep retrying.
        $this->answerCallbackQuery($callbackId);

        // If unauthorized user:
        if ($chatId != $this->authorizedChatId) {
            Log::warning("Unauthorized callback from chat ID: $chatId");
            return response('OK');
        }

        // If user is in the middle of the create‐booking flow, route them to that function:
        if ($this->isInCreateFlow($chatId)) {
            return $this->handleCreateBookingFlow($chatId, $callbackData, true);
        }

        // Otherwise, handle top‐level callback commands (like “main_menu”, etc.)
        switch ($callbackData) {
            case 'create_booking':
                // Start the multi‐step create flow
                return $this->startCreateBookingFlow($chatId);
            // ... e.g. 'list_bookings' or other top-level callbacks ...
            default:
                $this->sendTelegramMessage($chatId, "Callback not recognized: {$callbackData}");
                return response('OK');
        }
    }

    /**
     * Process typed commands like /create, /update, etc.
     */
    protected function processCommand(Request $request)
    {
        $message = $request->input('message');
        if (!$message) {
            Log::error("No message in webhook payload.");
            return response('OK');
        }

        $chatId = $message['chat']['id'] ?? null;
        $text   = trim($message['text'] ?? '');

        // If unauthorized user:
        if ($chatId != $this->authorizedChatId) {
            Log::warning("Unauthorized chat ID: $chatId");
            return response('OK');
        }

        if (strpos($text, '/create') === 0) {
            // Start the multi‐step flow
            return $this->startCreateBookingFlow($chatId);
        }

        // If user is in the create flow and they typed something, treat that as input
        if ($this->isInCreateFlow($chatId)) {
            return $this->handleCreateBookingFlow($chatId, $text, false);
        }

        // Other commands or fallback
        // e.g.: /list => $this->listBookings($chatId)
        $this->sendTelegramMessage($chatId, "Command not recognized. Type /create to begin booking.");
        return response('OK');
    }

    /* ----------------------------------------------------------------------
     *  Multi-Step Create Booking Flow
     * ---------------------------------------------------------------------- */

    /**
     * Step 0: Start the flow. Send inline keyboard to pick GUEST or “Cancel.”
     */
    protected function startCreateBookingFlow($chatId)
    {
        // Initialize state for this user’s booking creation
        self::$conversationState[$chatId] = [
            'step'  => 1,
            'data'  => [], // Will hold guest_id, date/time, tour_id, ...
        ];

        // Build an inline keyboard of existing guests (limit to say 5 or 10 for demo)
        $guests = Guest::take(5)->get();
        $keyboard = [];
        foreach ($guests as $guest) {
            // Each button uses the guest’s ID as callback data
            $keyboard[] = [
                [
                    'text'          => $guest->full_name,
                    'callback_data' => "guest_id:{$guest->id}",
                ],
            ];
        }
        // Add a “Cancel” row
        $keyboard[] = [
            ['text' => 'Cancel', 'callback_data' => 'cancel_create'],
        ];

        $payload = [
            'chat_id'      => $chatId,
            'text'         => "Step 1: Select a Guest (showing first 5) or Cancel:",
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ];

        $this->sendRawTelegramRequest('sendMessage', $payload);
        return response('OK');
    }

    /**
     * Check if user is in the middle of creating a booking.
     */
    protected function isInCreateFlow($chatId)
    {
        return isset(self::$conversationState[$chatId]) 
            && isset(self::$conversationState[$chatId]['step']);
    }

    /**
     * This function is called repeatedly whenever the user
     * picks an inline button or types input during the create flow.
     */
    protected function handleCreateBookingFlow($chatId, $input, $isCallback)
    {
        // Access user’s current step
        $step  = self::$conversationState[$chatId]['step'];
        $data  = self::$conversationState[$chatId]['data'];

        // Check if user canceled
        if ($input === 'cancel_create') {
            unset(self::$conversationState[$chatId]);
            $this->sendTelegramMessage($chatId, "Booking creation canceled.");
            return response('OK');
        }

        switch ($step) {
            case 1: // We expect the user to pick a GUEST from inline keyboard
                if ($isCallback && str_starts_with($input, 'guest_id:')) {
                    // Save the chosen guest_id
                    $guestId = str_replace('guest_id:', '', $input);
                    $data['guest_id'] = (int) $guestId;

                    // Move to step 2
                    self::$conversationState[$chatId]['step'] = 2;
                    self::$conversationState[$chatId]['data'] = $data;

                    // Ask for Tour Start Date & Time (we’ll let them type in for simplicity)
                    $this->sendTelegramMessage($chatId, "Step 2: Please type the Tour Start Date & Time (YYYY-MM-DD HH:MM). Example: 2025-03-15 09:00");
                } else {
                    // Invalid or user typed text instead of picking from keyboard
                    $this->sendTelegramMessage($chatId, "Please tap on one of the guest buttons.");
                }
                break;

            case 2: // They should type the date/time
                // Validate user typed date/time
                try {
                    $dateTime = Carbon::parse($input);
                    $data['booking_start_date_time'] = $dateTime->toDateTimeString();
                } catch (\Exception $e) {
                    $this->sendTelegramMessage($chatId, "Invalid date/time. Please try again. Example: 2025-03-15 09:00");
                    break;
                }

                // Move to step 3
                self::$conversationState[$chatId]['step'] = 3;
                self::$conversationState[$chatId]['data'] = $data;

                // Show inline keyboard for picking a Tour
                $tours = Tour::take(5)->get();
                $keyboard = [];
                foreach ($tours as $tour) {
                    $keyboard[] = [[
                        'text'          => $tour->title,
                        'callback_data' => "tour_id:{$tour->id}",
                    ]];
                }
                $keyboard[] = [['text' => 'Cancel', 'callback_data' => 'cancel_create']];

                $payload = [
                    'chat_id'      => $chatId,
                    'text'         => "Step 3: Select a Tour (showing first 5).",
                    'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
                ];
                $this->sendRawTelegramRequest('sendMessage', $payload);
                break;

            case 3: // User picks a Tour from inline keyboard
                if ($isCallback && str_starts_with($input, 'tour_id:')) {
                    $tourId          = str_replace('tour_id:', '', $input);
                    $data['tour_id'] = (int) $tourId;

                    // Move to step 4
                    self::$conversationState[$chatId]['step'] = 4;
                    self::$conversationState[$chatId]['data'] = $data;

                    // Show inline keyboard for picking a Guide
                    $guides = Guide::take(5)->get();
                    $keyboard = [];
                    $keyboard[] = [['text' => 'No guide', 'callback_data' => 'guide_id:0']];
                    foreach ($guides as $guide) {
                        $keyboard[] = [[
                            'text'          => $guide->full_name,
                            'callback_data' => "guide_id:{$guide->id}",
                        ]];
                    }
                    $keyboard[] = [['text' => 'Cancel', 'callback_data' => 'cancel_create']];

                    $payload = [
                        'chat_id'      => $chatId,
                        'text'         => "Step 4: Select a Guide (or choose No guide).",
                        'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
                    ];
                    $this->sendRawTelegramRequest('sendMessage', $payload);

                } else {
                    $this->sendTelegramMessage($chatId, "Please pick from the Tour buttons.");
                }
                break;

            case 4: // Guides
                if ($isCallback && str_starts_with($input, 'guide_id:')) {
                    $guideId = str_replace('guide_id:', '', $input);
                    $data['guide_id'] = (int) $guideId === 0 ? null : (int) $guideId;

                    // Move to step 5
                    self::$conversationState[$chatId]['step'] = 5;
                    self::$conversationState[$chatId]['data'] = $data;

                    // Show inline keyboard for picking a Driver
                    $drivers = Driver::take(5)->get();
                    $keyboard = [];
                    $keyboard[] = [['text' => 'No driver', 'callback_data' => 'driver_id:0']];
                    foreach ($drivers as $driver) {
                        $keyboard[] = [[
                            'text'          => $driver->full_name,
                            'callback_data' => "driver_id:{$driver->id}",
                        ]];
                    }
                    $keyboard[] = [['text' => 'Cancel', 'callback_data' => 'cancel_create']];

                    $payload = [
                        'chat_id'      => $chatId,
                        'text'         => "Step 5: Select a Driver (or choose No driver).",
                        'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
                    ];
                    $this->sendRawTelegramRequest('sendMessage', $payload);

                } else {
                    $this->sendTelegramMessage($chatId, "Please pick from the Guide options.");
                }
                break;

            case 5: // Driver
                if ($isCallback && str_starts_with($input, 'driver_id:')) {
                    $driverId = str_replace('driver_id:', '', $input);
                    $data['driver_id'] = (int) $driverId === 0 ? null : (int) $driverId;

                    // Step 6: prompt for “pickup_location” (text field)
                    self::$conversationState[$chatId]['step'] = 6;
                    self::$conversationState[$chatId]['data'] = $data;
                    $this->sendTelegramMessage($chatId, "Step 6: Please type the Pickup Location:");
                } else {
                    $this->sendTelegramMessage($chatId, "Please pick from the Driver options.");
                }
                break;

            case 6: // pickup_location
                $data['pickup_location'] = $input;

                // Move to step 7: dropoff_location
                self::$conversationState[$chatId]['step'] = 7;
                self::$conversationState[$chatId]['data'] = $data;
                $this->sendTelegramMessage($chatId, "Step 7: Please type the Dropoff Location:");
                break;

            case 7: // dropoff_location
                $data['dropoff_location'] = $input;

                // Move to step 8: booking_status
                self::$conversationState[$chatId]['step'] = 8;
                self::$conversationState[$chatId]['data'] = $data;

                // Show inline keyboard for booking_status
                $keyboard = [
                    [['text' => 'Pending',     'callback_data' => 'status:pending']],
                    [['text' => 'In Progress', 'callback_data' => 'status:in_progress']],
                    [['text' => 'Finished',    'callback_data' => 'status:finished']],
                    [['text' => 'Cancel',      'callback_data' => 'cancel_create']],
                ];
                $payload = [
                    'chat_id'      => $chatId,
                    'text'         => "Step 8: Choose Booking Status:",
                    'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
                ];
                $this->sendRawTelegramRequest('sendMessage', $payload);
                break;

            case 8: // booking_status
                if ($isCallback && str_starts_with($input, 'status:')) {
                    $status = str_replace('status:', '', $input);
                    $data['booking_status'] = $status;

                    // Move to step 9: booking_source
                    self::$conversationState[$chatId]['step'] = 9;
                    self::$conversationState[$chatId]['data'] = $data;

                    // Show inline keyboard for booking_source
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
                        'chat_id'      => $chatId,
                        'text'         => "Step 9: Choose Booking Source:",
                        'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
                    ];
                    $this->sendRawTelegramRequest('sendMessage', $payload);
                } else {
                    $this->sendTelegramMessage($chatId, "Please choose a status from the buttons.");
                }
                break;

            case 9: // booking_source
                if ($isCallback && str_starts_with($input, 'source:')) {
                    $source = str_replace('source:', '', $input);
                    $data['booking_source'] = $source;

                    // Move to step 10: special_requests
                    self::$conversationState[$chatId]['step'] = 10;
                    self::$conversationState[$chatId]['data'] = $data;
                    $this->sendTelegramMessage($chatId, "Step 10: Any special requests? (Type 'none' if not).");
                } else {
                    $this->sendTelegramMessage($chatId, "Please pick a booking source from the buttons.");
                }
                break;

            case 10: // special_requests
                $data['special_requests'] = $input;

                // All fields collected. Confirm or create
                self::$conversationState[$chatId]['step'] = 11;
                self::$conversationState[$chatId]['data'] = $data;

                // Show a summary with a Confirm/Cancel
                $summary = $this->formatBookingSummary($data);
                $keyboard = [
                    [['text' => 'Confirm', 'callback_data' => 'confirm_create']],
                    [['text' => 'Cancel',  'callback_data' => 'cancel_create']],
                ];
                $payload = [
                    'chat_id'      => $chatId,
                    'text'         => "Review your booking:\n\n{$summary}",
                    'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
                ];
                $this->sendRawTelegramRequest('sendMessage', $payload);
                break;

            case 11: // waiting for confirm
                if ($input === 'confirm_create') {
                    // Actually create the booking
                    try {
                        $booking = Booking::create([
                            'guest_id'                => $data['guest_id'],
                            'booking_start_date_time' => $data['booking_start_date_time'],
                            'tour_id'                 => $data['tour_id'],
                            'guide_id'                => $data['guide_id'],
                            'driver_id'               => $data['driver_id'],
                            'pickup_location'         => $data['pickup_location'],
                            'dropoff_location'        => $data['dropoff_location'],
                            'booking_status'          => $data['booking_status'],
                            'booking_source'          => $data['booking_source'],
                            'special_requests'        => $data['special_requests'],
                            // ... any other fields needed ...
                        ]);

                        unset(self::$conversationState[$chatId]); // Clear flow

                        $this->sendTelegramMessage($chatId, "Booking created successfully! ID: {$booking->id}");
                    } catch (\Exception $e) {
                        Log::error("Error creating booking: " . $e->getMessage());
                        $this->sendTelegramMessage($chatId, "Error creating booking. Please try again.");
                    }
                } else {
                    $this->sendTelegramMessage($chatId, "Please confirm or cancel.");
                }
                break;
        }

        return response('OK');
    }

    /**
     * Format the summary of chosen fields for final confirmation.
     */
    protected function formatBookingSummary(array $data)
    {
        // Attempt to load related objects for a nicer summary
        $guestName  = optional(Guest::find($data['guest_id']))->full_name ?? 'N/A';
        $tourTitle  = optional(Tour::find($data['tour_id']))->title ?? 'N/A';
        $guideName  = $data['guide_id'] 
            ? optional(Guide::find($data['guide_id']))->full_name 
            : 'No guide';
        $driverName = $data['driver_id']
            ? optional(Driver::find($data['driver_id']))->full_name
            : 'No driver';

        $dt = $data['booking_start_date_time'] ?? 'N/A';

        $summary  = "Guest: $guestName\n";
        $summary .= "Tour Start: $dt\n";
        $summary .= "Tour: $tourTitle\n";
        $summary .= "Guide: $guideName\n";
        $summary .= "Driver: $driverName\n";
        $summary .= "Pickup: {$data['pickup_location']}\n";
        $summary .= "Dropoff: {$data['dropoff_location']}\n";
        $summary .= "Status: {$data['booking_status']}\n";
        $summary .= "Source: {$data['booking_source']}\n";
        $summary .= "Requests: {$data['special_requests']}\n";

        return $summary;
    }

    /* ----------------------------------------------------------------------
     *  General Telegram Bot Utility Methods
     * ---------------------------------------------------------------------- */

    protected function sendTelegramMessage($chatId, $text)
    {
        if (!$this->botToken) {
            Log::error("TELEGRAM_BOT_TOKEN missing in config('services.telegram_bot.token').");
            return false;
        }

        $url = "https://api.telegram.org/bot{$this->botToken}/sendMessage";

        try {
            $response = Http::post($url, [
                'chat_id' => $chatId,
                'text'    => $text,
            ]);
            if ($response->failed()) {
                Log::error("Failed to sendTelegramMessage: " . $response->body());
                return false;
            }
            return true;
        } catch (\Exception $e) {
            Log::error("Exception while sendTelegramMessage: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send any payload to a Telegram method using file_get_contents or Http.
     */
    protected function sendRawTelegramRequest($method, array $payload)
    {
        if (!$this->botToken) {
            Log::error("TELEGRAM_BOT_TOKEN missing in config('services.telegram_bot.token').");
            return false;
        }

        $url = "https://api.telegram.org/bot{$this->botToken}/{$method}";
        try {
            // Using file_get_contents (you can also use Http::post)
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
                Log::error("Failed to connect to Telegram method $method");
                return false;
            }
            return true;
        } catch (\Exception $e) {
            Log::error("Exception while sendRawTelegramRequest: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Acknowledge callback query so Telegram stops resending it.
     */
    protected function answerCallbackQuery($callbackId, $text = 'Got it!', $showAlert = false)
    {
        $payload = [
            'callback_query_id' => $callbackId,
            'text'              => $text,
            'show_alert'        => $showAlert,
        ];
        $this->sendRawTelegramRequest('answerCallbackQuery', $payload);
    }
}
