<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use GuzzleHttp\Client;  // For sending HTTP requests to Telegram
use App\Models\Driver;
use App\Models\Guide;
use App\Models\Chat;

class TelegramDriverGuideSignUpController extends Controller
{
    protected $botToken;
    protected $telegramClient;

    public function __construct()
    {
        // Pull the token from config/services.php (which in turn reads .env)
        $this->botToken = config('services.telegram.bot_token');

        // Create a reusable Guzzle client pointing to Telegram's API
        $this->telegramClient = new Client([
            'base_uri' => 'https://api.telegram.org',
        ]);
    }

    /**
     * Main webhook handler for Telegram updates.
     */
    public function handleWebhook(Request $request)
    {
        // Get the entire update payload
        $update = $request->all();

        // Extract chat_id, text, and possibly contact
        $chatId  = data_get($update, 'message.chat.id');
        $text    = data_get($update, 'message.text');
        $contact = data_get($update, 'message.contact'); 
        // if user shares contact, Telegram includes: 
        //   "contact" => ["phone_number" => "...", "first_name" => "...", etc.]

        // If the user typed "/start", show a "Request Contact" button:
        if ($text === '/start') {
            $this->sendContactRequest($chatId, "Please share your phone number by tapping the button below.");
            return response()->json(['ok' => true]);
        }

        // If we received a contact (i.e. the user tapped the "Share Phone" button)
        if ($contact) {
            // Extract the phone number from the contact
            $phoneNumber = $contact['phone_number'];

            // Look up driver/guide
            $driver = Driver::where('phone', $phoneNumber)->first();
            $guide  = null;
            if (! $driver) {
                $guide = Guide::where('phone', $phoneNumber)->first();
            }

            // If no match, tell the user to contact Javohit, etc.
            if (! $driver && ! $guide) {
                $this->sendMessage($chatId, "No matching driver or guide found. Please contact Javohit at +998 91 555 08 08.");
                return response()->json(['ok' => true]);
            }

            // We found either a driver or a guide
            if ($driver) {
                $name   = $driver->name;
                $userId = $driver->id;
                $type   = 'driver';
            } else {
                $name   = $guide->name;
                $userId = $guide->id;
                $type   = 'guide';
            }

            // Store or update the chat in DB
            Chat::updateOrCreate(
                ['chat_id' => $chatId],
                [
                    'name'      => $name,
                    'id'   => $userId,
                    'user_type' => $type,
                ]
            );

            // Send success message
            $this->sendMessage($chatId, "Thanks, $name! We have recognized you as a $type and saved your chat.");
            return response()->json(['ok' => true]);
        }

        // Fallback if we get something else (text commands, etc.)
        $this->sendMessage($chatId, "Please type /start to begin or share your phone number using the button.");
        return response()->json(['ok' => true]);
    }

    /**
     * Send a basic text message to a given chat.
     */
    private function sendMessage($chatId, $text)
    {
        $this->telegramClient->post("/bot{$this->botToken}/sendMessage", [
            'json' => [
                'chat_id' => $chatId,
                'text'    => $text,
            ],
        ]);
    }

    /**
     * Send a keyboard that requests the user's contact (phone number).
     */
    private function sendContactRequest($chatId, $prompt)
    {
        // A special reply_markup with a "request_contact" button
        $replyMarkup = [
            'keyboard' => [
                [
                    [
                        'text'            => 'Share Phone Number',
                        'request_contact' => true
                    ]
                ]
            ],
            'resize_keyboard'   => true,
            'one_time_keyboard' => true
        ];

        // Use JSON-encode for the 'reply_markup'
        $this->telegramClient->post("/bot{$this->botToken}/sendMessage", [
            'json' => [
                'chat_id'      => $chatId,
                'text'         => $prompt,
                'reply_markup' => $replyMarkup,
            ],
        ]);
    }
}
