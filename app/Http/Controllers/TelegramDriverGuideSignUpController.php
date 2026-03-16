<?php

namespace App\Http\Controllers;

use App\Models\Driver;
use App\Models\Guide;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramDriverGuideSignUpController extends Controller
{
    protected string $botToken;
    protected Client $telegramClient;

    public function __construct()
    {
        $this->botToken = env('TELEGRAM_BOT_TOKEN_DRIVER_GUIDE');
        $this->telegramClient = new Client(['base_uri' => 'https://api.telegram.org']);
    }

    public function handleWebhook(Request $request): \Illuminate\Http\JsonResponse
    {
        $update  = $request->all();
        $chatId  = data_get($update, 'message.chat.id');
        $text    = data_get($update, 'message.text');
        $contact = data_get($update, 'message.contact');

        Log::info('DriverGuideBot: update', compact('chatId', 'text'));

        // /start — ask for phone number
        if ($text === '/start') {
            $this->sendContactRequest($chatId,
                "👋 Salom! Telefon raqamingizni ulashing, biz sizni aniqlaylik."
            );
            return response()->json(['ok' => true]);
        }

        // Contact shared — look up and register
        if ($contact) {
            $phone = $contact['phone_number'];
            // Normalise: ensure leading +
            $phone = str_starts_with($phone, '+') ? $phone : '+' . $phone;

            Log::info("DriverGuideBot: phone shared = {$phone}");

            // Search drivers first, then guides
            $driver = Driver::where('phone01', $phone)->orWhere('phone02', $phone)->first();
            $guide  = $driver ? null : Guide::where('phone01', $phone)->orWhere('phone02', $phone)->first();

            if (!$driver && !$guide) {
                // Try without leading + as fallback
                $phoneNoPlus = ltrim($phone, '+');
                $driver = Driver::where('phone01', $phoneNoPlus)->orWhere('phone02', $phoneNoPlus)->first();
                $guide  = $driver ? null : Guide::where('phone01', $phoneNoPlus)->orWhere('phone02', $phoneNoPlus)->first();
            }

            if (!$driver && !$guide) {
                Log::warning("DriverGuideBot: no match for phone {$phone}");
                $this->sendMessage($chatId,
                    "❌ Sizning raqamingiz topilmadi. Iltimos, Odiljon bilan bog'laning: +998 91 555 08 08"
                );
                return response()->json(['ok' => true]);
            }

            // Save telegram_chat_id
            if ($driver) {
                $driver->update(['telegram_chat_id' => (string) $chatId]);
                $name = $driver->full_name;
                $type = 'haydovchi (driver)';
            } else {
                $guide->update(['telegram_chat_id' => (string) $chatId]);
                $name = $guide->full_name;
                $type = 'gid (guide)';
            }

            Log::info("DriverGuideBot: registered {$type} {$name} → chat_id {$chatId}");

            $this->sendMessage($chatId,
                "✅ Rahmat, {$name}!\n\nSiz {$type} sifatida ro'yxatdan o'tdingiz. Endi tur rejalari avtomatik ravishda sizga yuboriladi. 🗓️"
            );

            return response()->json(['ok' => true]);
        }

        // Fallback
        $this->sendMessage($chatId, "Boshlash uchun /start ni bosing.");
        return response()->json(['ok' => true]);
    }

    private function sendMessage(int|string $chatId, string $text): void
    {
        try {
            $this->telegramClient->post("/bot{$this->botToken}/sendMessage", [
                'json' => [
                    'chat_id'    => $chatId,
                    'text'       => $text,
                    'parse_mode' => 'HTML',
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('DriverGuideBot: sendMessage error', ['error' => $e->getMessage()]);
        }
    }

    private function sendContactRequest(int|string $chatId, string $prompt): void
    {
        $replyMarkup = [
            'keyboard' => [[
                ['text' => '📱 Telefon raqamni ulashish', 'request_contact' => true]
            ]],
            'resize_keyboard'   => true,
            'one_time_keyboard' => true,
        ];

        try {
            $this->telegramClient->post("/bot{$this->botToken}/sendMessage", [
                'json' => [
                    'chat_id'      => $chatId,
                    'text'         => $prompt,
                    'reply_markup' => $replyMarkup,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('DriverGuideBot: sendContactRequest error', ['error' => $e->getMessage()]);
        }
    }
}
