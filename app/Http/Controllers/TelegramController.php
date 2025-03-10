<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class TelegramController extends Controller
{
    /**
     * The chat ID that is authorized to use this bot.
     * Replace with your actual Telegram user ID if you only
     * want to allow commands from a specific user.
     */
    protected $authorizedChatId = '38738713';

    /**
     * Your bot token from config/services.php.
     */
    protected $botToken;

    /**
     * Constructor to initialize the bot token from config().
     */
    public function __construct()
    {
        // Ensure you have 'telegram_bot' => ['token' => env('TELEGRAM_BOT_TOKEN')] in config/services.php
        $this->botToken = config('services.telegram_bot.token');
    }

    /**
     * Webhook endpoint that Telegram calls.
     */
    public function handleWebhook(Request $request)
    {
        // Telegram can send either a message (typed command) or a callback_query (button press).
        $callbackQuery = $request->input('callback_query');
        if ($callbackQuery) {
            // User tapped on inline keyboard button
            return $this->handleCallbackQuery($callbackQuery);
        }

        // Otherwise, treat it as a standard incoming message.
        return $this->processCommand($request);
    }

    /**
     * Handle Telegram inline keyboard button presses.
     */
    protected function handleCallbackQuery(array $callbackQuery)
    {
        $data   = $callbackQuery['data'] ?? null;  // e.g. "list_bookings"
        $chatId = $callbackQuery['message']['chat']['id'] ?? null;

        // You can optionally confirm to Telegram that we received the callback.
        $this->answerCallbackQuery($callbackQuery['id']);

        // If you’re using authorizedChatId logic, check that:
        if ($chatId != $this->authorizedChatId) {
            Log::warning("Unauthorized chat ID (callback): $chatId");
            return response('OK');
        }

        switch ($data) {
            case 'main_menu':
                $this->sendMainMenu($chatId, "Main Menu");
                break;
            case 'list_bookings':
                // Reuse the same logic as the /list command
                $this->listBookings($chatId);
                break;
            case 'create_booking':
                // For simplicity, just show a message. Typically you'd collect more info here.
                $this->sendTelegramMessage($chatId, "To create a booking, type:\n/create name:John Doe; tour:City Tour; date:2025-03-15");
                break;
            case 'update_booking':
                $this->sendTelegramMessage($chatId, "To update a booking, type:\n/update 1 name:Jane Doe; date:2025-03-20");
                break;
            case 'delete_booking':
                $this->sendTelegramMessage($chatId, "To delete a booking, type:\n/delete 1");
                break;
            case 'refresh_bookings':
                // Just call listBookings again
                $this->listBookings($chatId);
                break;
            default:
                $this->sendTelegramMessage($chatId, "Callback not recognized: $data");
                break;
        }

        return response('OK');
    }

    /**
     * Process incoming Telegram commands from the request (typed by user).
     */
    public function processCommand(Request $request)
    {
        $message = $request->input('message');
        if (!$message) {
            Log::error("No message provided in webhook payload.");
            return response('OK'); // Return 200 so Telegram doesn't retry
        }

        $chatId = $message['chat']['id'] ?? null;
        if ($chatId != $this->authorizedChatId) {
            // If unauthorized, just log and return 200 to avoid repeated retries
            Log::warning("Unauthorized chat ID: $chatId");
            return response('OK');
        }

        $text = trim($message['text'] ?? '');
        if (empty($text)) {
            $this->sendTelegramMessage($chatId, "Empty command.");
            return response('OK');
        }

        // Route the command based on its prefix
        if (strpos($text, '/start') === 0) {
            // Show an inline menu so user can tap to choose an action
            $this->sendMainMenu($chatId, "Main Menu");
            return response('OK');
        } elseif (strpos($text, '/create') === 0) {
            return $this->createBooking($chatId, $text);
        } elseif (strpos($text, '/update') === 0) {
            return $this->updateBooking($chatId, $text);
        } elseif (strpos($text, '/delete') === 0) {
            return $this->deleteBooking($chatId, $text);
        } elseif (strpos($text, '/list') === 0) {
            return $this->listBookings($chatId);
        } else {
            $this->sendTelegramMessage($chatId, "Command not recognized.");
            return response('OK');
        }
    }

    /**
     *  Show an inline keyboard main menu so user can choose an action.
     */
    protected function sendMainMenu($chatId, $headerText)
    {
        $keyboard = [
            [
                ['text' => 'List Bookings',   'callback_data' => 'list_bookings'],
                ['text' => 'Create Booking',  'callback_data' => 'create_booking'],
            ],
            [
                ['text' => 'Update Booking',  'callback_data' => 'update_booking'],
                ['text' => 'Delete Booking',  'callback_data' => 'delete_booking'],
            ],
            [
                ['text' => 'Refresh Bookings','callback_data' => 'refresh_bookings'],
            ],
        ];

        $payload = [
            'chat_id'      => $chatId,
            'text'         => $headerText,
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ];

        $this->sendRawTelegramRequest('sendMessage', $payload);
    }

    /**
     * Create a booking.
     * Example usage in chat: /create name:John Doe; tour:City Tour; date:2025-03-15
     */
    protected function createBooking($chatId, $text)
    {
        // Remove the command portion to parse parameters.
        $dataPart = trim(str_replace('/create', '', $text));
        $params   = $this->parseParams($dataPart);

        // Validate required parameters
        if (!isset($params['name']) || !isset($params['tour']) || !isset($params['date'])) {
            $this->sendTelegramMessage($chatId, "Missing parameters. Required: name, tour, date.");
            return response('OK');
        }

        try {
            $booking = Booking::create([
                'name' => $params['name'],
                'tour' => $params['tour'],
                'date' => $params['date'],
            ]);
        } catch (\Exception $e) {
            Log::error("Error creating booking: " . $e->getMessage());
            $this->sendTelegramMessage($chatId, "Error creating booking.");
            return response('OK');
        }

        $responseText  = "Booking created with ID: {$booking->id}\n";
        $responseText .= "Name: {$booking->name}\n";
        $responseText .= "Tour: {$booking->tour}\n";
        $responseText .= "Date: {$booking->date}";

        $this->sendTelegramMessage($chatId, $responseText);
        return response('OK');
    }

    /**
     * Update a booking.
     * Example usage in chat: /update 1 name:Jane Doe; date:2025-03-20
     */
    protected function updateBooking($chatId, $text)
    {
        $parts = explode(' ', $text, 3); // [command, bookingId, data]
        if (count($parts) < 3) {
            $this->sendTelegramMessage($chatId, "Invalid format. Use: /update {id} key:value; key:value");
            return response('OK');
        }

        $bookingId = $parts[1];
        $dataPart  = $parts[2];

        $booking = Booking::find($bookingId);
        if (!$booking) {
            $this->sendTelegramMessage($chatId, "Booking not found.");
            return response('OK');
        }

        $params = $this->parseParams($dataPart);

        try {
            $booking->update($params);
        } catch (\Exception $e) {
            Log::error("Error updating booking: " . $e->getMessage());
            $this->sendTelegramMessage($chatId, "Error updating booking.");
            return response('OK');
        }

        $responseText  = "Booking with ID {$booking->id} updated.\n";
        $responseText .= "Name: {$booking->name}\n";
        $responseText .= "Tour: {$booking->tour}\n";
        $responseText .= "Date: {$booking->date}";

        $this->sendTelegramMessage($chatId, $responseText);
        return response('OK');
    }

    /**
     * Delete a booking.
     * Example usage in chat: /delete {id}
     */
    protected function deleteBooking($chatId, $text)
    {
        $parts = explode(' ', $text, 2);
        if (count($parts) < 2) {
            $this->sendTelegramMessage($chatId, "Invalid format. Use: /delete {id}");
            return response('OK');
        }

        $bookingId = $parts[1];
        $booking   = Booking::find($bookingId);

        if (!$booking) {
            $this->sendTelegramMessage($chatId, "Booking not found.");
            return response('OK');
        }

        try {
            $booking->delete();
        } catch (\Exception $e) {
            Log::error("Error deleting booking: " . $e->getMessage());
            $this->sendTelegramMessage($chatId, "Error deleting booking.");
            return response('OK');
        }

        $this->sendTelegramMessage($chatId, "Booking with ID {$bookingId} deleted.");
        return response('OK');
    }

    /**
     * List up to 5 upcoming bookings and show inline keyboard.
     * Example usage in chat: /list
     */
    protected function listBookings($chatId)
    {
        // 1) Fetch upcoming bookings
        $bookings = Booking::where('booking_start_date_time', '>', now())
            ->orderBy('booking_start_date_time', 'asc')
            ->take(5)
            ->get();

        // 2) Handle empty results
        if ($bookings->isEmpty()) {
            $this->sendInlineMessage($chatId, 'No upcoming bookings found.');
            return response('OK');
        }

        // 3) Build the message text
        $responseText = "Upcoming Bookings:\n\n";
        foreach ($bookings as $booking) {
            $formattedDate = Carbon::parse($booking->booking_start_date_time)->format('M j Y');
            $responseText .= "Guest: {$booking->guest->full_name}\n"
                           . "Tour: {$booking->tour->title}\n"
                           . "Source: {$booking->booking_source}\n"
                           . "Date: {$formattedDate}\n"
                           . "----------------------------------------\n\n";
        }

        // 4) Inline keyboard to refresh or go to main menu
        $inlineKeyboard = [
            [
                ['text' => 'Refresh',    'callback_data' => 'refresh_bookings'],
                ['text' => 'Main Menu',  'callback_data' => 'main_menu'],
            ],
        ];

        // 5) Construct the payload
        $payload = [
            'chat_id'      => $chatId,
            'text'         => $responseText,
            'reply_markup' => json_encode([
                'inline_keyboard' => $inlineKeyboard
            ]),
        ];

        // 6) Send request
        $this->sendRawTelegramRequest('sendMessage', $payload);
        return response('OK');
    }

    /**
     * Helper: parse a parameter string into an associative array.
     * Example input: "name:John Doe; tour:City Tour; date:2025-03-15"
     */
    protected function parseParams($data)
    {
        $params = [];
        $pairs = explode(';', $data);
        foreach ($pairs as $pair) {
            $pair = trim($pair);
            if (empty($pair)) {
                continue;
            }
            $parts = explode(':', $pair, 2);
            if (count($parts) === 2) {
                $key   = trim($parts[0]);
                $value = trim($parts[1]);
                $params[$key] = $value;
            }
        }
        return $params;
    }

    /**
     * Sends a simple text message to the Telegram chat (no inline keyboard).
     */
    protected function sendTelegramMessage($chatId, $text)
    {
        if (!$this->botToken) {
            Log::error("TELEGRAM_BOT_TOKEN is not set or missing in config('services.telegram_bot.token').");
            return false;
        }

        $url = "https://api.telegram.org/bot{$this->botToken}/sendMessage";

        try {
            $response = Http::post($url, [
                'chat_id' => $chatId,
                'text'    => $text,
            ]);

            if ($response->failed()) {
                Log::error("Failed to send Telegram message: " . $response->body());
                return false;
            }
            return true;
        } catch (\Exception $e) {
            Log::error("Exception while sending Telegram message: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Sends a text-only message with no keyboard using file_get_contents (alternative).
     */
    protected function sendInlineMessage($chatId, $text)
    {
        $payload = [
            'chat_id' => $chatId,
            'text'    => $text,
        ];
        $this->sendRawTelegramRequest('sendMessage', $payload);
    }

    /**
     * Generic method to send any payload to any Telegram method using file_get_contents().
     */
    protected function sendRawTelegramRequest($method, array $payload)
    {
        if (!$this->botToken) {
            Log::error("TELEGRAM_BOT_TOKEN is not set or missing in config('services.telegram_bot.token').");
            return false;
        }

        $url = "https://api.telegram.org/bot{$this->botToken}/{$method}";

        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\n",
                'content' => json_encode($payload),
            ],
        ]);

        try {
            $response = file_get_contents($url, false, $context);
            if ($response === false) {
                Log::error("Failed to connect to Telegram: {$method}");
                return false;
            }
            return true;
        } catch (\Exception $e) {
            Log::error("Exception while sending request to Telegram: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Acknowledge a callback query so Telegram doesn't keep re-sending it.
     */
    protected function answerCallbackQuery($callbackQueryId, $text = 'Got it!', $showAlert = false)
    {
        $payload = [
            'callback_query_id' => $callbackQueryId,
            'text'              => $text,
            'show_alert'        => $showAlert,
        ];

        $this->sendRawTelegramRequest('answerCallbackQuery', $payload);
    }
}
