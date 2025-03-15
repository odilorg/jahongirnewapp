<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use GuzzleHttp\Client;
use App\Models\Driver;
use App\Models\Guide;
use App\Models\Chat;

class TelegramDriverGuideSignUpController extends Controller
{
    protected $botToken;
    protected $telegramClient;

    public function __construct()
    {
        $this->botToken = env('TELEGRAM_BOT_TOKEN');
        $this->telegramClient = new Client([
            'base_uri' => 'https://api.telegram.org',
        ]);
    }

    public function handleWebhook(Request $request)
    {
        $update = $request->all();

        $chatId  = data_get($update, 'message.chat.id');
        $text    = data_get($update, 'message.text');
        $contact = data_get($update, 'message.contact');

        if ($text === '/start') {
            $this->sendContactRequest($chatId, "Please share your phone number by tapping the button below.");
            return response()->json(['ok' => true]);
        }

        if ($contact) {
            // get phone number from contact
            $phoneNumber = $contact['phone_number'];

            // Optional: strip "+" if your database stores numbers without plus
            // $phoneNumber = ltrim($phoneNumber, '+');

            // Look up driver:
            $driver = Driver::where('phone1', $phoneNumber)
                ->orWhere('phone2', $phoneNumber)
                ->first();

            // If not found, look up guide:
            $guide = null;
            if (! $driver) {
                $guide = Guide::where('phone1', $phoneNumber)
                    ->orWhere('phone2', $phoneNumber)
                    ->first();
            }

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

            // Store or update chat
            Chat::updateOrCreate(
                ['chat_id' => $chatId],
                [
                    'name'      => $name,
                    'id'        => $userId,  // or 'user_id' => $userId if your column is user_id
                    'user_type' => $type,
                ]
            );

            $this->sendMessage($chatId, "Thanks, $name! We have recognized you as a $type and saved your chat.");
            return response()->json(['ok' => true]);
        }

        // Fallback
        $this->sendMessage($chatId, "Please type /start to begin or share your phone number using the button.");
        return response()->json(['ok' => true]);
    }

    private function sendMessage($chatId, $text)
    {
        $this->telegramClient->post("/bot{$this->botToken}/sendMessage", [
            'json' => [
                'chat_id' => $chatId,
                'text'    => $text,
            ],
        ]);
    }

    private function sendContactRequest($chatId, $prompt)
    {
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

        $this->telegramClient->post("/bot{$this->botToken}/sendMessage", [
            'json' => [
                'chat_id'      => $chatId,
                'text'         => $prompt,
                'reply_markup' => $replyMarkup,
            ],
        ]);
    }
}
