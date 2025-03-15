<?php

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Models\Guide;
use App\Models\Driver;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramDriverGuideSignUpController extends Controller
{
    protected $botToken;
    protected $telegramClient;

    public function __construct()
    {
        // Read from .env (TELEGRAM_BOT_TOKEN_DRIVER_GUIDE) directly
        $this->botToken = env('TELEGRAM_BOT_TOKEN_DRIVER_GUIDE');

        // Log the token on instantiation
        Log::info('TelegramDriverGuideSignUpController: Bot token from env is: ' . ($this->botToken ?? 'NULL/EMPTY'));

        // Create a Guzzle client to interact with Telegram
        $this->telegramClient = new Client([
            'base_uri' => 'https://api.telegram.org',
        ]);
    }

    public function handleWebhook(Request $request)
    {
        // Grab the entire payload from Telegram
        $update = $request->all();
        Log::info('handleWebhook: Received update', $update);

        // Extract relevant fields
        $chatId  = data_get($update, 'message.chat.id');
        $text    = data_get($update, 'message.text');
        $contact = data_get($update, 'message.contact');

        Log::info("handleWebhook: chatId={$chatId}, text={$text}");
        if ($contact) {
            Log::info('handleWebhook: contact data', $contact);
        }

        // If user typed "/start", show a request-contact keyboard
        if ($text === '/start') {
            Log::info('handleWebhook: User invoked /start, sending contact request');
            $this->sendContactRequest($chatId, "Please share your phone number by tapping the button below.");
            return response()->json(['ok' => true]);
        }

        // If a contact was shared, handle phone-based lookup
        if ($contact) {
            $phoneNumber = $contact['phone_number'];
            Log::info("handleWebhook: user shared phone number '{$phoneNumber}'");

            // Example: strip "+" if your DB doesn't store it
            // $phoneNumber = ltrim($phoneNumber, '+');

            // Check Drivers table first
            $driver = Driver::where('phone01', $phoneNumber)
                            ->orWhere('phone02', $phoneNumber)
                            ->first();

            // If no Driver found, check Guides
            $guide = null;
            if (! $driver) {
                $guide = Guide::where('phone01', $phoneNumber)
                              ->orWhere('phone02', $phoneNumber)
                              ->first();
            }

            // If neither found, respond with error
            if (! $driver && ! $guide) {
                Log::info("handleWebhook: No matching driver or guide for phone='{$phoneNumber}'");
                $this->sendMessage($chatId, "No matching driver or guide found. Please contact Javohit at +998 91 555 08 08.");
                return response()->json(['ok' => true]);
            }

            // Determine which type it was
            if ($driver) {
                $name   = $driver->full_name;
                $userId = $driver->id;
                $type   = 'driver';
            } else {
                $name   = $guide->full_name;
                $userId = $guide->id;
                $type   = 'guide';
            }
            Log::info("handleWebhook: Found user [type={$type}, id={$userId}, name={$name}]");

            // Store/update the chat record
            Chat::updateOrCreate(
                ['chat_id' => $chatId],
                [
                    'name'      => $name,
                    'id'        => $userId,  // or use 'user_id' => $userId if that's your column
                    'user_type' => $type,
                ]
            );
            Log::info("handleWebhook: Chat record created/updated for chat_id={$chatId}");

            // Send success message
            $this->sendMessage($chatId, "Thanks, $name! We have recognized you as a $type and saved your chat.");
            return response()->json(['ok' => true]);
        }

        // Fallback if some other message was sent
        Log::info('handleWebhook: Received unknown or unexpected message, sending fallback info');
        $this->sendMessage($chatId, "Please type /start to begin or share your phone number using the button.");
        return response()->json(['ok' => true]);
    }

    /**
     * Send a text message back to the user.
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
     * Send a custom keyboard that requests the user's contact (phone number).
     */
    private function sendContactRequest($chatId, $prompt)
    {
        Log::info("sendContactRequest: chatId={$chatId}, prompt='{$prompt}'");

        // Keyboard with a single button that shares phone contact
        $replyMarkup = [
            'keyboard' => [
                [
                    [
                        'text'            => 'Поделиться телефон номером',
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
