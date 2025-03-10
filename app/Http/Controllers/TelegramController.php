<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class TelegramController extends Controller
{
    /**
     * The chat ID that is authorized to use this bot.
     * Replace with your actual Telegram user ID.
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
        // Make sure you have 'telegram_bot' => ['token' => env('TELEGRAM_BOT_TOKEN')] in config/services.php
        $this->botToken = config('services.telegram_bot.token');
    }

    /**
     * Webhook endpoint that Telegram calls.
     */
    public function handleWebhook(Request $request)
    {
        return $this->processCommand($request);
    }

    /**
     * Process incoming Telegram commands from the request.
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
        if (strpos($text, '/create') === 0) {
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
     * Create a booking.
     * Example usage in chat: /create name:John Doe; tour:City Tour; date:2025-03-15
     */
    protected function createBooking($chatId, $text)
    {
        // Remove the command portion to parse parameters.
        $dataPart = trim(str_replace('/create', '', $text));
        $params = $this->parseParams($dataPart);

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
     * List all bookings.
     * Example usage in chat: /list
     */
    // protected function listBookings($chatId)
    // {
    //     $bookings = Booking::all();
    //     if ($bookings->isEmpty()) {
    //         $this->sendTelegramMessage($chatId, "No bookings found.");
    //         return response('OK');
    //     }

    //     $responseText = "Bookings:\n";
    //     foreach ($bookings as $booking) {
    //         $responseText .= "Guest: {$booking->guest->full_name}, "
    //                        . "Tour: {$booking->tour->name}, "
    //                        . "Source: {$booking->booking_source}, "
    //                        . "Date: {$booking->booking_start_date_time}\n";
    //     }

    //     $this->sendTelegramMessage($chatId, $responseText);
    //     return response('OK');
    // }

    /**
     * Helper method to parse a parameter string into an associative array.
     * Example input: "name:John Doe; tour:City Tour; date:2025-03-15"
     */

     protected function listBookings($chatId)
{
    // Filter for upcoming bookings, order by start date, and limit to 5 results
    $bookings = Booking::where('booking_start_date_time', '>', now())
                        ->orderBy('booking_start_date_time', 'asc')
                        ->take(5)
                        ->get();

    if ($bookings->isEmpty()) {
        $this->sendTelegramMessage($chatId, "No upcoming bookings found.");
        return response('OK');
    }

    $responseText = "Upcoming Bookings:\n";
    foreach ($bookings as $booking) {
        $responseText .= "Guest: {$booking->guest->full_name}\n "
                       . "Tour: {$booking->tour->title}\n "
                       . "Source: {$booking->booking_source}\n "
                       . "Date: {$booking->booking_start_date_time}\n"
                       ."----------------------------------------\n\n";
    }

    $this->sendTelegramMessage($chatId, $responseText);
    return response('OK');
}

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
     * Sends a text message to the Telegram chat using the Bot API.
     */
    protected function sendTelegramMessage($chatId, $text)
    {
        if (!$this->botToken) {
            Log::error("TELEGRAM_BOT_TOKEN is not set in config('services.telegram_bot.token').");
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
}
