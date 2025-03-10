<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramController extends Controller
{
    // Set your authorized chat ID (replace with your actual chat ID)
    protected $authorizedChatId = '38738713';

    /**
     * Webhook endpoint that Telegram calls.
     */
    // public function handleWebhook(Request $request)
    // {
    //     return $this->processCommand($request);
    // }
    public function handleWebhook(Request $request)
    {
        try {
            Log::info('Webhook update received:', $request->all());
            // Place your processing logic here
            
            return response('OK', 200);
        } catch (\Exception $e) {
            Log::error('Webhook error: ' . $e->getMessage());
            return response('Server error', 500);
        }
    }
    
    /**
     * Process incoming Telegram commands.
     */
    public function processCommand(Request $request)
    {
        $message = $request->input('message');
        if (!$message) {
            Log::error("No message provided in webhook payload.");
            return response("No message provided", 400);
        }

        $chatId = $message['chat']['id'] ?? null;
        if ($chatId != $this->authorizedChatId) {
            return response('Unauthorized', 403);
        }

        $text = trim($message['text'] ?? '');
        if (empty($text)) {
            return response("Empty command", 400);
        }

        // Route the command based on its prefix.
        if (strpos($text, '/create') === 0) {
            return $this->createBooking($message);
        } elseif (strpos($text, '/update') === 0) {
            return $this->updateBooking($message);
        } elseif (strpos($text, '/delete') === 0) {
            return $this->deleteBooking($message);
        } elseif (strpos($text, '/list') === 0) {
            return $this->listBookings($message);
        } else {
            return response('Command not recognized.', 400);
        }
    }

    /**
     * Create a booking.
     * Expected format: /create name:John Doe; tour:City Tour; date:2025-03-15
     */
    protected function createBooking($message)
    {
        $text = $message['text'];
        // Remove the command portion to parse parameters.
        $dataPart = trim(str_replace('/create', '', $text));
        $params = $this->parseParams($dataPart);

        // Validate required parameters.
        if (!isset($params['name']) || !isset($params['tour']) || !isset($params['date'])) {
            return response('Missing parameters. Required: name, tour, date.', 400);
        }

        try {
            $booking = Booking::create([
                'name' => $params['name'],
                'tour' => $params['tour'],
                'date' => $params['date'],
                // Add additional fields here if needed.
            ]);
        } catch (\Exception $e) {
            Log::error("Error creating booking: " . $e->getMessage());
            return response("Error creating booking", 500);
        }

        $responseText  = "Booking created with ID: {$booking->id}\n";
        $responseText .= "Name: {$booking->name}\n";
        $responseText .= "Tour: {$booking->tour}\n";
        $responseText .= "Date: {$booking->date}";

        return response($responseText, 200);
    }

    /**
     * Update a booking.
     * Expected format: /update {id} name:Jane Doe; date:2025-03-20
     */
    protected function updateBooking($message)
    {
        $text = $message['text'];
        $parts = explode(' ', $text, 3); // [command, bookingId, data]
        if (count($parts) < 3) {
            return response('Invalid format. Use: /update {id} key:value; key:value', 400);
        }

        $bookingId = $parts[1];
        $dataPart = $parts[2];

        $booking = Booking::find($bookingId);
        if (!$booking) {
            return response('Booking not found.', 404);
        }

        $params = $this->parseParams($dataPart);

        try {
            $booking->update($params);
        } catch (\Exception $e) {
            Log::error("Error updating booking: " . $e->getMessage());
            return response("Error updating booking", 500);
        }

        $responseText  = "Booking with ID {$booking->id} updated.\n";
        $responseText .= "Name: {$booking->name}\n";
        $responseText .= "Tour: {$booking->tour}\n";
        $responseText .= "Date: {$booking->date}";

        return response($responseText, 200);
    }

    /**
     * Delete a booking.
     * Expected format: /delete {id}
     */
    protected function deleteBooking($message)
    {
        $text = $message['text'];
        $parts = explode(' ', $text, 2);
        if (count($parts) < 2) {
            return response('Invalid format. Use: /delete {id}', 400);
        }
        $bookingId = $parts[1];

        $booking = Booking::find($bookingId);
        if (!$booking) {
            return response('Booking not found.', 404);
        }

        try {
            $booking->delete();
        } catch (\Exception $e) {
            Log::error("Error deleting booking: " . $e->getMessage());
            return response("Error deleting booking", 500);
        }

        return response("Booking with ID {$bookingId} deleted.", 200);
    }

    /**
     * List all bookings.
     */
    protected function listBookings($message)
    {
        $bookings = Booking::all();
        if ($bookings->isEmpty()) {
            return response("No bookings found.", 200);
        }

        $responseText = "Bookings:\n";
        foreach ($bookings as $booking) {
            $responseText .= "ID: {$booking->id}, Name: {$booking->name}, Tour: {$booking->tour}, Date: {$booking->date}\n";
        }
        return response($responseText, 200);
    }

    /**
     * Helper method to parse a parameter string into an associative array.
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
                $key = trim($parts[0]);
                $value = trim($parts[1]);
                $params[$key] = $value;
            }
        }
        return $params;
    }
}
