<?php

namespace App\Http\Controllers;

use App\Models\Driver;
use App\Models\Guide;
use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TelegramDriverGuideSignUpController extends Controller
{
    protected ?string $botToken;
    protected Client  $telegramClient;

    // Owner Telegram chat ID — receives conflict alerts
    const OWNER_CHAT_ID = '38738713';

    public function __construct()
    {
        $this->botToken       = config('services.driver_guide_bot.token', env('TELEGRAM_BOT_TOKEN_DRIVER_GUIDE'));
        $this->telegramClient = new Client(['base_uri' => 'https://api.telegram.org']);
    }

    public function handleWebhook(Request $request): \Illuminate\Http\JsonResponse
    {
        $update        = $request->all();
        $chatId        = data_get($update, 'message.chat.id')
                      ?? data_get($update, 'callback_query.message.chat.id');
        $text          = data_get($update, 'message.text');
        $contact       = data_get($update, 'message.contact');
        $callbackQuery = data_get($update, 'callback_query');

        Log::info('DriverGuideBot: update', compact('chatId', 'text'));

        // ── Callback query (calendar tap) ──────────────────────────────────
        if ($callbackQuery) {
            $this->answerCallbackQuery(data_get($callbackQuery, 'id'));
            return $this->handleCalendarCallback($callbackQuery);
        }

        // ── /start ─────────────────────────────────────────────────────────
        if ($text === '/start') {
            $driver = Driver::where('telegram_chat_id', (string) $chatId)->first();
            if ($driver) {
                $this->sendMainMenu($chatId, $driver->first_name);
            } else {
                $this->sendContactRequest($chatId,
                    "👋 Salom! Telefon raqamingizni ulashing, biz sizni aniqlaylik."
                );
            }
            return response()->json(['ok' => true]);
        }

        // ── /calendar ──────────────────────────────────────────────────────
        if ($text === '/calendar') {
            $driver = Driver::where('telegram_chat_id', (string) $chatId)->first();
            if ($driver) {
                $this->sendCalendar($chatId, $driver->id, Carbon::now('Asia/Tashkent'));
            }
            return response()->json(['ok' => true]);
        }

        // ── 📋 My bookings ─────────────────────────────────────────────────
        if ($text === '📋 Mening bronlarim') {
            $driver = Driver::where('telegram_chat_id', (string) $chatId)->first();
            if ($driver) {
                $this->sendMyBookings($chatId, $driver->id);
            }
            return response()->json(['ok' => true]);
        }

        // ── 📅 My calendar ─────────────────────────────────────────────────
        if ($text === '📅 Mening jadvalim') {
            $driver = Driver::where('telegram_chat_id', (string) $chatId)->first();
            if ($driver) {
                $this->sendCalendar($chatId, $driver->id, Carbon::now('Asia/Tashkent'));
            }
            return response()->json(['ok' => true]);
        }

        // ── Contact shared → registration ──────────────────────────────────
        if ($contact) {
            return $this->handleContactRegistration($chatId, $contact);
        }

        // ── Fallback ───────────────────────────────────────────────────────
        $driver = Driver::where('telegram_chat_id', (string) $chatId)->first();
        if ($driver) {
            $this->sendMainMenu($chatId, $driver->first_name);
        } else {
            $this->sendMessage($chatId, "Boshlash uchun /start ni bosing.");
        }

        return response()->json(['ok' => true]);
    }

    // =========================================================================
    // Calendar UI
    // =========================================================================

    private function sendCalendar(int|string $chatId, int $driverId, Carbon $month, ?int $messageId = null): void
    {
        $keyboard = $this->buildCalendarKeyboard($driverId, $month);
        $text     = "📅 <b>" . $month->translatedFormat('F Y') . "</b>\n\n✅ = bo'sh  ❌ = band\nKunni bosib o'zgartiring:";

        if ($messageId) {
            $this->editMessage($chatId, $messageId, $text, $keyboard);
        } else {
            $this->sendInlineKeyboard($chatId, $text, $keyboard);
        }
    }

    private function buildCalendarKeyboard(int $driverId, Carbon $month): array
    {
        $firstDay  = $month->copy()->startOfMonth();
        $lastDay   = $month->copy()->endOfMonth();
        $today     = Carbon::now('Asia/Tashkent')->startOfDay();
        $yearEnd   = Carbon::create(2026, 12, 31, 0, 0, 0, 'Asia/Tashkent');

        // Fetch this driver's availability for this month
        $availability = DB::table('driver_availability')
            ->where('driver_id', $driverId)
            ->whereBetween('available_date', [$firstDay->toDateString(), $lastDay->toDateString()])
            ->pluck('is_available', 'available_date')
            ->toArray();

        $rows   = [];
        // Month/Year header row
        $rows[] = [[
            'text'          => '◀',
            'callback_data' => "CAL|PREV|{$driverId}|" . $month->format('Y-m'),
        ], [
            'text'          => $month->format('M Y'),
            'callback_data' => 'CAL|NOOP',
        ], [
            'text'          => '▶',
            'callback_data' => "CAL|NEXT|{$driverId}|" . $month->format('Y-m'),
        ]];

        // Day-of-week header
        $rows[] = array_map(fn($d) => ['text' => $d, 'callback_data' => 'CAL|NOOP'],
            ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su']);

        // Build day grid
        $current  = $firstDay->copy();
        $startDow = ($firstDay->dayOfWeek + 6) % 7; // Mon=0
        $row      = array_fill(0, $startDow, ['text' => ' ', 'callback_data' => 'CAL|NOOP']);

        while ($current->lte($lastDay)) {
            $dateStr   = $current->toDateString();
            $isPast    = $current->lt($today);
            $isBeyond  = $current->gt($yearEnd);
            $isAvail   = $availability[$dateStr] ?? false;

            if ($isPast || $isBeyond) {
                $label = $current->day;
                $row[] = ['text' => (string) $label, 'callback_data' => 'CAL|NOOP'];
            } else {
                $icon  = $isAvail ? '✅' : '❌';
                $row[] = [
                    'text'          => $icon . $current->day,
                    'callback_data' => "CAL|TOGGLE|{$driverId}|{$dateStr}|" . $month->format('Y-m'),
                ];
            }

            if (count($row) === 7) {
                $rows[] = $row;
                $row    = [];
            }

            $current->addDay();
        }

        // Pad last row
        if (!empty($row)) {
            while (count($row) < 7) {
                $row[] = ['text' => ' ', 'callback_data' => 'CAL|NOOP'];
            }
            $rows[] = $row;
        }

        // Done button
        $rows[] = [['text' => '✅ Tayyor', 'callback_data' => "CAL|DONE|{$driverId}"]];

        return $rows;
    }

    private function handleCalendarCallback($callbackQuery): \Illuminate\Http\JsonResponse
    {
        $chatId    = data_get($callbackQuery, 'message.chat.id');
        $messageId = data_get($callbackQuery, 'message.message_id');
        $data      = data_get($callbackQuery, 'data', '');

        $parts = explode('|', $data);
        $action = $parts[1] ?? 'NOOP';

        if ($action === 'NOOP') {
            return response()->json(['ok' => true]);
        }

        $driverId = (int) ($parts[2] ?? 0);

        if ($action === 'TOGGLE' && isset($parts[3])) {
            $date     = $parts[3]; // Y-m-d
            $monthStr = $parts[4] ?? Carbon::now('Asia/Tashkent')->format('Y-m');

            // Get current value
            $current = DB::table('driver_availability')
                ->where('driver_id', $driverId)
                ->where('available_date', $date)
                ->value('is_available');

            $newValue = !$current; // toggle

            DB::table('driver_availability')->upsert(
                ['driver_id' => $driverId, 'available_date' => $date, 'is_available' => $newValue, 'created_at' => now(), 'updated_at' => now()],
                ['driver_id', 'available_date'],
                ['is_available', 'updated_at']
            );

            Log::info("DriverGuideBot: availability toggled", [
                'driver_id' => $driverId,
                'date'      => $date,
                'available' => $newValue,
            ]);

            // ── Conflict check ────────────────────────────────────────────
            if (!$newValue) {
                $this->checkAndAlertConflict($driverId, $date);
            }

            // Redraw calendar
            [$year, $month] = explode('-', $monthStr);
            $monthCarbon = Carbon::create((int) $year, (int) $month, 1, 0, 0, 0, 'Asia/Tashkent');
            $this->sendCalendar($chatId, $driverId, $monthCarbon, $messageId);
        }

        if ($action === 'PREV' && isset($parts[3])) {
            [$year, $month] = explode('-', $parts[3]);
            $prev = Carbon::create((int) $year, (int) $month, 1, 0, 0, 0, 'Asia/Tashkent')->subMonth();
            if ($prev->gte(Carbon::now('Asia/Tashkent')->startOfMonth())) {
                $this->sendCalendar($chatId, $driverId, $prev, $messageId);
            }
        }

        if ($action === 'NEXT' && isset($parts[3])) {
            [$year, $month] = explode('-', $parts[3]);
            $next = Carbon::create((int) $year, (int) $month, 1, 0, 0, 0, 'Asia/Tashkent')->addMonth();
            if ($next->lte(Carbon::create(2026, 12, 1, 0, 0, 0, 'Asia/Tashkent'))) {
                $this->sendCalendar($chatId, $driverId, $next, $messageId);
            }
        }

        if ($action === 'DONE') {
            $driver = Driver::find($driverId);
            $name   = $driver?->first_name ?? 'aka';
            $this->editMessageText($chatId, $messageId,
                "✅ Jadval saqlandi! Rahmat, {$name} aka.\n\nO'zgartirish uchun /calendar ni bosing."
            );
        }

        return response()->json(['ok' => true]);
    }

    private function checkAndAlertConflict(int $driverId, string $date): void
    {
        $driver = Driver::find($driverId);
        if (!$driver) return;

        $bookings = DB::table('bookings')
            ->join('guests', 'bookings.guest_id', '=', 'guests.id')
            ->join('tours', 'bookings.tour_id', '=', 'tours.id')
            ->where('bookings.driver_id', $driverId)
            ->where('bookings.booking_status', 'confirmed')
            ->whereDate('bookings.booking_start_date_time', $date)
            ->select(['bookings.booking_number', 'tours.title', 'guests.first_name', 'guests.last_name'])
            ->get();

        if ($bookings->isEmpty()) return;

        $driverName = $driver->first_name . ' ' . $driver->last_name;
        $lines = ["⚠️ <b>Konflikt!</b> {$driverName} {$date} kuni band deb belgiladi, lekin shu kuni buyurtmalar bor:\n"];
        foreach ($bookings as $b) {
            $lines[] = "• {$b->first_name} {$b->last_name} — {$b->title} [{$b->booking_number}]";
        }
        $lines[] = "\nHaydovchini almashtirish kerak bo'lishi mumkin!";

        $this->sendMessage(self::OWNER_CHAT_ID, implode("\n", $lines));

        Log::warning('DriverGuideBot: conflict detected', [
            'driver_id' => $driverId,
            'date'      => $date,
            'bookings'  => $bookings->count(),
        ]);
    }

    // =========================================================================
    // Registration
    // =========================================================================

    private function handleContactRegistration(int|string $chatId, array $contact): \Illuminate\Http\JsonResponse
    {
        $phone = $contact['phone_number'];
        $phone = str_starts_with($phone, '+') ? $phone : '+' . $phone;

        Log::info("DriverGuideBot: phone shared = {$phone}");

        $driver = Driver::where('phone01', $phone)->orWhere('phone02', $phone)->first();
        $guide  = $driver ? null : Guide::where('phone01', $phone)->orWhere('phone02', $phone)->first();

        if (!$driver && !$guide) {
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

        if ($driver) {
            $driver->update(['telegram_chat_id' => (string) $chatId]);
            $name = $driver->first_name;
            $type = 'haydovchi (driver)';
            $id   = $driver->id;
        } else {
            $guide->update(['telegram_chat_id' => (string) $chatId]);
            $name = $guide->first_name;
            $type = 'gid (guide)';
            $id   = null; // guide calendar not yet implemented
        }

        Log::info("DriverGuideBot: registered {$type} {$name} → chat_id {$chatId}");

        // Remove the share-phone keyboard after successful registration
        try {
            $this->telegramClient->post("/bot{$this->botToken}/sendMessage", [
                'json' => [
                    'chat_id'      => $chatId,
                    'text'         => "✅ Rahmat, {$name}!\n\nSiz {$type} sifatida ro'yxatdan o'tdingiz. Endi tur rejalari avtomatik ravishda sizga yuboriladi. 🗓️",
                    'parse_mode'   => 'HTML',
                    'reply_markup' => ['remove_keyboard' => true],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('DriverGuideBot: sendMessage error', ['error' => $e->getMessage()]);
        }

        // Show calendar for drivers
        if ($driver && $id) {
            sleep(1);
            $this->sendCalendar($chatId, $id, Carbon::now('Asia/Tashkent'));
        }

        return response()->json(['ok' => true]);
    }

    // =========================================================================
    // My Bookings
    // =========================================================================

    private function sendMyBookings(int|string $chatId, int $driverId): void
    {
        $bookings = DB::table('bookings')
            ->join('guests', 'bookings.guest_id', '=', 'guests.id')
            ->join('tours', 'bookings.tour_id', '=', 'tours.id')
            ->where('bookings.driver_id', $driverId)
            ->where('bookings.booking_status', 'confirmed')
            ->whereDate('bookings.booking_start_date_time', '>=', now('Asia/Tashkent')->toDateString())
            ->orderBy('bookings.booking_start_date_time')
            ->select([
                'bookings.booking_number',
                'bookings.booking_start_date_time',
                'bookings.pickup_location',
                'guests.number_of_people',
                'guests.first_name',
                'guests.last_name',
                'tours.title',
            ])
            ->get();

        if ($bookings->isEmpty()) {
            $this->sendMessageWithMenu($chatId, "📋 Hozircha tayinlangan bronlar yo'q.");
            return;
        }

        $lines = ["📋 <b>Sizning bronlaringiz:</b>\n"];
        foreach ($bookings as $b) {
            $date    = Carbon::parse($b->booking_start_date_time)->timezone('Asia/Tashkent');
            $pax     = $b->number_of_people ?? 0;
            $pickup  = $b->pickup_location ?: 'Samarkand';
            $lines[] = "🗓 <b>" . $date->format('D, d M Y') . "</b> — " . $date->format('H:i');
            $lines[] = "🏕 " . $b->title;
            $lines[] = "👤 {$b->first_name} {$b->last_name}" . ($pax ? " ({$pax} pax)" : '');
            $lines[] = "📍 {$pickup}";
            $lines[] = "📋 {$b->booking_number}";
            $lines[] = "";
        }

        $this->sendMessageWithMenu($chatId, implode("\n", $lines));
    }

    // =========================================================================
    // Menus
    // =========================================================================

    private function sendMainMenu(int|string $chatId, string $name): void
    {
        $this->sendMessageWithMenu($chatId, "👋 Salom, <b>{$name}</b> aka!\n\nQuyidagi tugmalardan foydalaning:");
    }

    private function sendMessageWithMenu(int|string $chatId, string $text): void
    {
        try {
            $this->telegramClient->post("/bot{$this->botToken}/sendMessage", [
                'json' => [
                    'chat_id'      => $chatId,
                    'text'         => $text,
                    'parse_mode'   => 'HTML',
                    'reply_markup' => [
                        'keyboard' => [
                            [
                                ['text' => '📋 Mening bronlarim'],
                                ['text' => '📅 Mening jadvalim'],
                            ],
                        ],
                        'resize_keyboard'  => true,
                        'persistent'       => true,
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('DriverGuideBot: sendMessageWithMenu error', ['error' => $e->getMessage()]);
        }
    }

    // =========================================================================
    // Telegram API helpers
    // =========================================================================

    private function sendMessage(int|string $chatId, string $text): void
    {
        try {
            $this->telegramClient->post("/bot{$this->botToken}/sendMessage", [
                'json' => ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'],
            ]);
        } catch (\Exception $e) {
            Log::error('DriverGuideBot: sendMessage error', ['error' => $e->getMessage()]);
        }
    }

    private function sendInlineKeyboard(int|string $chatId, string $text, array $keyboard): void
    {
        try {
            $this->telegramClient->post("/bot{$this->botToken}/sendMessage", [
                'json' => [
                    'chat_id'      => $chatId,
                    'text'         => $text,
                    'parse_mode'   => 'HTML',
                    'reply_markup' => ['inline_keyboard' => $keyboard],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('DriverGuideBot: sendInlineKeyboard error', ['error' => $e->getMessage()]);
        }
    }

    private function editMessage(int|string $chatId, int $messageId, string $text, array $keyboard): void
    {
        try {
            $this->telegramClient->post("/bot{$this->botToken}/editMessageText", [
                'json' => [
                    'chat_id'      => $chatId,
                    'message_id'   => $messageId,
                    'text'         => $text,
                    'parse_mode'   => 'HTML',
                    'reply_markup' => ['inline_keyboard' => $keyboard],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('DriverGuideBot: editMessage error', ['error' => $e->getMessage()]);
        }
    }

    private function editMessageText(int|string $chatId, int $messageId, string $text): void
    {
        try {
            $this->telegramClient->post("/bot{$this->botToken}/editMessageText", [
                'json' => ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text, 'parse_mode' => 'HTML'],
            ]);
        } catch (\Exception $e) {
            Log::error('DriverGuideBot: editMessageText error', ['error' => $e->getMessage()]);
        }
    }

    private function sendContactRequest(int|string $chatId, string $prompt): void
    {
        try {
            $this->telegramClient->post("/bot{$this->botToken}/sendMessage", [
                'json' => [
                    'chat_id'      => $chatId,
                    'text'         => $prompt,
                    'reply_markup' => [
                        'keyboard'          => [[['text' => '📱 Telefon raqamni ulashish', 'request_contact' => true]]],
                        'resize_keyboard'   => true,
                        'one_time_keyboard' => true,
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('DriverGuideBot: sendContactRequest error', ['error' => $e->getMessage()]);
        }
    }

    private function answerCallbackQuery(string $callbackQueryId): void
    {
        try {
            $this->telegramClient->post("/bot{$this->botToken}/answerCallbackQuery", [
                'json' => ['callback_query_id' => $callbackQueryId],
            ]);
        } catch (\Exception $e) {
            // silent
        }
    }
}
