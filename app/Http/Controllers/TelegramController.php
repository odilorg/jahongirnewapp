<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Booking;
// use App\Http\Resources\BookingResource; // Uncomment if you plan to use it for output formatting

class TelegramController extends Controller
{
    // Set your authorized chat ID here so only your Telegram account can interact
    protected $authorizedChatId = '38738713';

    /**
     * Process incoming Telegram commands.
     * For now, this method is called manually (or via a test route) since we're not setting up webhooks.
     */
    public function processCommand(Request $request)
    {
        // Retrieve message payload from the request
        $message = $request->input('message');
        $chatId = $message['chat']['id'] ?? null;

        // Simple authorization: only process if chat id matches your authorized chat id
        if ($chatId != $this->authorizedChatId) {
            return response('Unauthorized', 403);
        }

        $text = trim($message['text'] ?? '');

        // Determine which command is being sent
        if (strpos($text, '/create') === 0) {
            return $this->createBooking($message);
        } elseif (strpos($text, '/update') === 0) {
            return $this->updateBooking($message);
        } elseif (strpos($text, '/delete') === 0) {
            return $this->deleteBooking($message);
        } elseif (strpos($text, '/list') === 0) {
            return $this->listBookings($message);
        } else {
            return response('Command not recognized.');
        }
    }

    /**
     * Create a booking.
     * Expected format: /create name:John Doe; tour:City Tour; date:2025-03-15
     */
    protected function createBooking($message)
    {
        $text = $message['text'];
        // Remove the command portion to parse parameters
        $dataPart = trim(str_replace('/create', '', $text));
        $params = $this->parseParams($dataPart);

        // Validate required parameters
        if (!isset($params['name']) || !isset($params['tour']) || !isset($params['date'])) {
            return response('Missing parameters. Required: name, tour, date.');
        }

        // Create a new booking record
        $booking = Booking::create([
            'name' => $params['name'],
            'tour' => $params['tour'],
            'date' => $params['date'],
            // Add additional fields as necessary
        ]);

        return response("Booking created with ID: " . $booking->id);
    }

    /**
     * Update a booking.
     * Expected format: /update 1 name:Jane Doe; date:2025-03-20
     */
    protected function updateBooking($message)
    {
        $text = $message['text'];
        $parts = explode(' ', $text, 3); // [command, bookingId, data]
        if (count($parts) < 3) {
            return response('Invalid format. Use: /update {id} key:value; key:value');
        }

        $bookingId = $parts[1];
        $dataPart = $parts[2];

        $booking = Booking::find($bookingId);
        if (!$booking) {
            return response('Booking not found.');
        }

        $params = $this->parseParams($dataPart);

        $booking->update($params);

        return response("Booking with ID {$booking->id} updated.");
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
            return response('Invalid format. Use: /delete {id}');
        }
        $bookingId = $parts[1];

        $booking = Booking::find($bookingId);
        if (!$booking) {
            return response('Booking not found.');
        }

        $booking->delete();

        return response("Booking with ID {$booking->id} deleted.");
    }

    /**
     * List all bookings.
     */
    protected function listBookings($message)
    {
        $bookings = Booking::all();
        if ($bookings->isEmpty()) {
            return response("No bookings found.");
        }

        $responseText = "Bookings:\n";
        foreach ($bookings as $booking) {
            $responseText .= "ID: {$booking->id}, Name: {$booking->name}, Tour: {$booking->tour}, Date: {$booking->date}\n";
        }
        return response($responseText);
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
            if (empty($pair)) continue;
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
