<?php

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Models\Guide;
use App\Models\Driver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client;  // For sending HTTP requests to Telegram

class TelegramDriverGuideSignUpController extends Controller
{
    protected $botToken;
    protected $telegramClient;

    public function __construct()
    {
        // Pull the token from config/services.php (which in turn reads .env)
        $this->botToken = env('TELEGRAM_BOT_TOKEN_DRIVER_GUIDE');

        // Log out the bot token to confirm it's not empty
        Log::info('TelegramDriverGuideSignUpController: Bot token is: ' . ($this->botToken ?? 'NULL/EMPTY'));

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
        Log::info('handleWebhook: Received update', $update);

        // Extract chat_id, text, and possibly contact
        $chatId  = data_get($update, 'message.chat.id');
        $text    = data_get($update, 'message.text');
        $contact = data_get($update, 'message.contact'); 
        Log::info("handleWebhook: chatId={$chatId}, text={$text}");

        // If contact is present, let's log it
        if ($contact) {
            Log::info('handleWebhook: contact data', $contact);
        }

        // If the user typed "/start", show a "Request Contact" button:
        if ($text === '/start') {
            Log::info('handleWebhook: User invoked /start, sending contact request');
            $this->sendContactRequest($chatId, "Please share your phone number by tapping the button below.");
            return response()->json(['ok' => true]);
        }

        // If we received a contact (i.e. the user tapped the "Share Phone" button)
        if ($contact) {
            // Extract the phone number from the contact
            $phoneNumber = $contact['phone_number'];
            Log::info("handleWebhook: user shared phone number {$phoneNumber}");

            // Look up driver/guide
            $driver = Driver::where('phone', $phoneNumber)->first();
            $guide  = null;
            if (! $driver) {
                $guide = Guide::where('phone', $phoneNumber)->first();
            }

            // If no match, tell the user to contact Javohit, etc.
            if (! $driver && ! $guide) {
                Log::info("handleWebhook: No matching driver or guide found for phone={$phoneNumber}");
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
            Log::info("handleWebhook: Found user [type={$type}, id={$userId}, name={$name}]");

            // Store or update the chat in DB
            Chat::updateOrCreate(
                ['chat_id' => $chatId],
                [
                    'name'      => $name,
                    'id'        => $userId, // If your Chat table uses "user_id", change to 'user_id'
                    'user_type' => $type,
                ]
            );
            Log::info("handleWebhook: Chat record created/updated for chat_id={$chatId}");

            // Send success message
            $this->sendMessage($chatId, "Thanks, $name! We have recognized you as a $type and saved your chat.");
            return response()->json(['ok' => true]);
        }

        // Fallback if we get something else (text commands, etc.)
        Log::info('handleWebhook: Received other message, sending fallback info');
        $this->sendMessage($chatId, "Please type /start to begin or share your phone number using the button.");
        return response()->json(['ok' => true]);
    }

    /**
     * Send a basic text message to a given chat.
     */
    private function sendMessage($chatId, $text)
    {
        Log::info("sendMessage: Sending message to chat_id={$chatId}, text='{$text}'");

        try {
            $response = $this->telegramClient->post("/bot{$this->botToken}/sendMessage", [
                'json' => [
                    'chat_id' => $chatId,
                    'text'    => $text,
                ],
            ]);
            Log::info('sendMessage: Telegram response', [
                'status_code' => $response->getStatusCode(),
                'body'        => $response->getBody()->getContents(),
            ]);
        } catch (\Exception $e) {
            Log::error('sendMessage: Error sending message to Telegram', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send a keyboard that requests the user's contact (phone number).
     */
    private function sendContactRequest($chatId, $prompt)
    {
        Log::info("sendContactRequest: chatId={$chatId}, prompt='{$prompt}'");

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

        try {
            $response = $this->telegramClient->post("/bot{$this->botToken}/sendMessage", [
                'json' => [
                    'chat_id'      => $chatId,
                    'text'         => $prompt,
                    'reply_markup' => $replyMarkup,
                ],
            ]);
            Log::info('sendContactRequest: Telegram response', [
                'status_code' => $response->getStatusCode(),
                'body'        => $response->getBody()->getContents(),
            ]);
        } catch (\Exception $e) {
            Log::error('sendContactRequest: Error sending keyboard to Telegram', [
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
