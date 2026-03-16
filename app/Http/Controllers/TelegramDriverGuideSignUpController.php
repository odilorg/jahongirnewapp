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

    const OWNER_CHAT_ID = '38738713';

    public function __construct()
    {
        $this->botToken       = config('services.driver_guide_bot.token', env('TELEGRAM_BOT_TOKEN_DRIVER_GUIDE'));
        $this->telegramClient = new Client(['base_uri' => 'https://api.telegram.org']);
    }

    // =========================================================================
    // Webhook entry point
    // =========================================================================

    public function handleWebhook(Request $request): \Illuminate\Http\JsonResponse
    {
        $update        = $request->all();
        $chatId        = data_get($update, 'message.chat.id')
                      ?? data_get($update, 'callback_query.message.chat.id');
        $text          = data_get($update, 'message.text');
        $contact       = data_get($update, 'message.contact');
        $callbackQuery = data_get($update, 'callback_query');

        Log::info('DriverGuideBot: update', compact('chatId', 'text'));

        // ── Inline calendar button taps ────────────────────────────────────
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

        // ── Contact shared → registration ──────────────────────────────────
        if ($contact) {
            return $this->handleContactRegistration($chatId, $contact);
        }

        // ── Persistent menu buttons ────────────────────────────────────────
        $driver = Driver::where('telegram_chat_id', (string) $chatId)->first();

        if ($text === '📋 Mening bronlarim') {
            if ($driver) $this->sendMyBookings($chatId, $driver->id);
            return response()->json(['ok' => true]);
        }

        if ($text === '📅 Mening jadvalim' || $text === '/calendar') {
            if ($driver) $this->sendCalendar($chatId, $driver->id, Carbon::now('Asia/Tashkent'));
            return response()->json(['ok' => true]);
        }

        // ── Fallback ───────────────────────────────────────────────────────
        if ($driver) {
            $this->sendMainMenu($chatId, $driver->first_name);
        } else {
            $this->sendMessage($chatId, "Boshlash uchun /start ni bosing.");
        }

        return response()->json(['ok' => true]);
    }

    // =========================================================================
    // Registration
    // =========================================================================

    private function handleContactRegistration(int|string $chatId, array $contact): \Illuminate\Http\JsonResponse
    {
        $phone = $contact['phone_number'];
        $phone = str_starts_with($phone, '+') ? $phone : '+' . $phone;

        Log::info("DriverGuideBot: phone shared = {$phone}");

        // Try with + prefix
        $driver = Driver::where('phone01', $phone)->orWhere('phone02', $phone)->first();
        $guide  = $driver ? null : Guide::where('phone01', $phone)->orWhere('phone02', $phone)->first();

        // Try without + prefix
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
        } else {
            $guide->update(['telegram_chat_id' => (string) $chatId]);
            $name = $guide->first_name;
            $type = 'gid (guide)';
        }

        Log::info("DriverGuideBot: registered {$type} {$name} → chat_id {$chatId}");

        // 1. Confirm registration + remove phone-share keyboard + set persistent menu
        $this->sendMessageWithMenu(
            $chatId,
            "✅ Rahmat, <b>{$name}</b>!\n\nSiz {$type} sifatida ro'yxatdan o'tdingiz. Endi tur rejalari avtomatik ravishda sizga yuboriladi. 🗓️\n\nQuyidagi tugmalardan foydalaning:"
        );

        // 2. Auto-show calendar for drivers
        if ($driver) {
            sleep(1);
            $this->sendCalendar($chatId, $driver->id, Carbon::now('Asia/Tashkent'));
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
            ->whereDate('bookings.booking_start_date_time', '>=', Carbon::now('Asia/Tashkent')->toDateString())
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
            $date   = Carbon::parse($b->booking_start_date_time)->timezone('Asia/Tashkent');
            $pax    = $b->number_of_people ? " ({$b->number_of_people} pax)" : '';
            $pickup = $b->pickup_location ?: 'Samarkand';

            $lines[] = "━━━━━━━━━━━━━━━━━";
            $lines[] = "🗓 <b>" . $date->format('D, d M Y') . "</b> soat <b>" . $date->format('H:i') . "</b>";
            $lines[] = "🏕 " . $b->title;
            $lines[] = "👤 {$b->first_name} {$b->last_name}{$pax}";
            $lines[] = "📍 " . $pickup;
            $lines[] = "📋 <code>{$b->booking_number}</code>";
        }
        $lines[] = "━━━━━━━━━━━━━━━━━";

        $this->sendMessageWithMenu($chatId, implode("\n", $lines));
    }

    // =========================================================================
    // Calendar UI
    // =========================================================================

    private function sendCalendar(int|string $chatId, int $driverId, Carbon $month, ?int $messageId = null): void
    {
        $keyboard = $this->buildCalendarKeyboard($driverId, $month);
        $text     = "📅 <b>" . $month->format('F Y') . "</b>\n\n✅ = bo'sh  ❌ = band\nKunni bosib o'zgartiring:";

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

        $availability = DB::table('driver_availability')
            ->where('driver_id', $driverId)
            ->whereBetween('available_date', [$firstDay->toDateString(), $lastDay->toDateString()])
            ->pluck('is_available', 'available_date')
            ->toArray();

        $rows = [];

        // Header: prev / month-year / next
        $rows[] = [
            ['text' => '◀', 'callback_data' => "CAL|PREV|{$driverId}|" . $month->format('Y-m')],
            ['text' => $month->format('M Y'), 'callback_data' => 'CAL|NOOP'],
            ['text' => '▶', 'callback_data' => "CAL|NEXT|{$driverId}|" . $month->format('Y-m')],
        ];

        // Day-of-week labels
        $rows[] = array_map(
            fn($d) => ['text' => $d, 'callback_data' => 'CAL|NOOP'],
            ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su']
        );

        // Day grid
        $current  = $firstDay->copy();
        $startDow = ($firstDay->dayOfWeek + 6) % 7; // Mon = 0
        $row      = array_fill(0, $startDow, ['text' => ' ', 'callback_data' => 'CAL|NOOP']);

        while ($current->lte($lastDay)) {
            $dateStr = $current->toDateString();
            $isPast  = $current->lt($today);
            $isFar   = $current->gt($yearEnd);
            $isAvail = $availability[$dateStr] ?? false;

            if ($isPast || $isFar) {
                $row[] = ['text' => (string) $current->day, 'callback_data' => 'CAL|NOOP'];
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
        $parts     = explode('|', $data);
        $action    = $parts[1] ?? 'NOOP';
        $driverId  = (int) ($parts[2] ?? 0);

        if ($action === 'NOOP') {
            return response()->json(['ok' => true]);
        }

        if ($action === 'TOGGLE' && isset($parts[3])) {
            $date     = $parts[3];
            $monthStr = $parts[4] ?? Carbon::now('Asia/Tashkent')->format('Y-m');

            $current  = DB::table('driver_availability')
                ->where('driver_id', $driverId)
                ->where('available_date', $date)
                ->value('is_available');

            $newValue = !$current;

            DB::table('driver_availability')->upsert(
                [
                    'driver_id'      => $driverId,
                    'available_date' => $date,
                    'is_available'   => $newValue,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ],
                ['driver_id', 'available_date'],
                ['is_available', 'updated_at']
            );

            Log::info("DriverGuideBot: availability toggled", [
                'driver_id' => $driverId,
                'date'      => $date,
                'available' => $newValue,
            ]);

            if (!$newValue) {
                $this->checkAndAlertConflict($driverId, $date);
            }

            [$year, $mon] = explode('-', $monthStr);
            $this->sendCalendar($chatId, $driverId,
                Carbon::create((int) $year, (int) $mon, 1, 0, 0, 0, 'Asia/Tashkent'),
                $messageId
            );
        }

        if ($action === 'PREV' && isset($parts[3])) {
            [$year, $mon] = explode('-', $parts[3]);
            $prev = Carbon::create((int) $year, (int) $mon, 1, 0, 0, 0, 'Asia/Tashkent')->subMonth();
            if ($prev->gte(Carbon::now('Asia/Tashkent')->startOfMonth())) {
                $this->sendCalendar($chatId, $driverId, $prev, $messageId);
            }
        }

        if ($action === 'NEXT' && isset($parts[3])) {
            [$year, $mon] = explode('-', $parts[3]);
            $next = Carbon::create((int) $year, (int) $mon, 1, 0, 0, 0, 'Asia/Tashkent')->addMonth();
            if ($next->lte(Carbon::create(2026, 12, 1, 0, 0, 0, 'Asia/Tashkent'))) {
                $this->sendCalendar($chatId, $driverId, $next, $messageId);
            }
        }

        if ($action === 'DONE') {
            $driver = Driver::find($driverId);
            $name   = $driver?->first_name ?? 'aka';
            // Edit the calendar message away, then send menu
            $this->editMessageText($chatId, $messageId,
                "✅ Jadval saqlandi! Rahmat, <b>{$name}</b> aka."
            );
            sleep(1);
            $this->sendMessageWithMenu($chatId, "Bosh menyu 👇");
        }

        return response()->json(['ok' => true]);
    }

    // =========================================================================
    // Conflict detection
    // =========================================================================

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

        $driverName = trim("{$driver->first_name} {$driver->last_name}");
        $lines      = ["⚠️ <b>Konflikt!</b> {$driverName} {$date} kuni band deb belgiladi, lekin shu kuni buyurtmalar bor:\n"];
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
    // Menus
    // =========================================================================

    private function sendMainMenu(int|string $chatId, string $name): void
    {
        $this->sendMessageWithMenu(
            $chatId,
            "👋 Salom, <b>{$name}</b> aka!\n\nQuyidagi tugmalardan foydalaning:"
        );
    }

    /**
     * Send a message with the persistent bottom keyboard (📋 bronlar + 📅 jadval).
     * This keyboard stays visible until explicitly removed.
     */
    private function sendMessageWithMenu(int|string $chatId, string $text): void
    {
        try {
            $this->telegramClient->post("/bot{$this->botToken}/sendMessage", [
                'json' => [
                    'chat_id'      => $chatId,
                    'text'         => $text,
                    'parse_mode'   => 'HTML',
                    'reply_markup' => [
                        'keyboard'         => [
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
