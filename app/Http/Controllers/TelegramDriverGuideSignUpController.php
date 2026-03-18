<?php

namespace App\Http\Controllers;

use App\Models\Driver;
use App\Models\Guide;
use App\Models\Partner;
use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TelegramDriverGuideSignUpController extends Controller
{
    protected string  $botToken;
    protected string  $webhookSecret;
    protected string  $ownerChatId;
    protected Client  $telegramClient;

    public function __construct()
    {
        $this->botToken      = config('services.driver_guide_bot.token', '');
        $this->webhookSecret = config('services.driver_guide_bot.webhook_secret', '');
        $this->ownerChatId   = config('services.driver_guide_bot.owner_chat_id', '38738713');
        $this->telegramClient = new Client(['base_uri' => 'https://api.telegram.org']);
    }

    // =========================================================================
    // Webhook entry point
    // =========================================================================

    public function handleWebhook(Request $request): \Illuminate\Http\JsonResponse
    {
        // Webhook secret validation is now handled by verify.telegram.webhook middleware

        $data = $request->all();
        $updateId = $data['update_id'] ?? null;

        $webhook = \App\Models\IncomingWebhook::create([
            'source'      => 'telegram:driver',
            'event_id'    => $updateId ? "driver:{$updateId}" : null,
            'payload'     => $data,
            'status'      => \App\Models\IncomingWebhook::STATUS_PENDING,
            'received_at' => now(),
        ]);

        \App\Jobs\ProcessTelegramUpdateJob::dispatch('driver', $webhook->id);

        return response()->json(['ok' => true]);
    }

    public function processUpdate(array $update): void
    {
        $chatId        = (string) (data_get($update, 'message.chat.id')
                      ?? data_get($update, 'callback_query.message.chat.id') ?? '');
        $text          = data_get($update, 'message.text');
        $contact       = data_get($update, 'message.contact');
        $callbackQuery = data_get($update, 'callback_query');

        if (!$chatId) return;

        // Phone auth only works in private chats — ignore group messages
        $chatType = data_get($update, 'message.chat.type', data_get($update, 'callback_query.message.chat.type', 'private'));
        if ($chatType !== 'private') return;

        Log::info('DriverGuideBot: update', compact('chatId', 'text'));

        // ── Inline calendar button taps ────────────────────────────────────
        if ($callbackQuery) {
            $this->answerCallbackQuery(data_get($callbackQuery, 'id'));
            $this->handleCalendarCallback($chatId, $callbackQuery);
            return;
        }

        // ── /start ─────────────────────────────────────────────────────────
        if ($text === '/start') {
            $driver  = Driver::where('telegram_chat_id', $chatId)->first();
            $partner = !$driver ? Partner::where('telegram_chat_id', $chatId)->first() : null;

            if ($driver) {
                $this->sendMainMenu($chatId, $driver->first_name);
            } elseif ($partner) {
                $this->sendPartnerMenu($chatId, $partner->name);
            } else {
                $this->sendContactRequest($chatId,
                    "👋 Salom! Telefon raqamingizni ulashing, biz sizni aniqlaylik."
                );
            }
            return;
        }

        // ── Contact shared → registration ──────────────────────────────────
        if ($contact) {
            $this->handleContactRegistration($chatId, $contact);
            return;
        }

        // ── Resolve role: driver or partner ───────────────────────────────
        $driver  = Driver::where('telegram_chat_id', $chatId)->first();
        $partner = !$driver ? Partner::where('telegram_chat_id', $chatId)->first() : null;

        // ── Partner menu buttons ───────────────────────────────────────────
        if ($partner) {
            if ($text === '📋 Bronlarim') {
                $this->sendPartnerBookings($chatId, $partner);
            } else {
                $this->sendPartnerMenu($chatId, $partner->name);
            }
            return;
        }

        // ── Driver menu buttons ────────────────────────────────────────────
        if ($text === '📋 Mening bronlarim') {
            $driver
                ? $this->sendMyBookings($chatId, $driver->id)
                : $this->sendMessage($chatId, "Boshlash uchun /start ni bosing.");
            return;
        }

        if ($text === '📅 Mening jadvalim' || $text === '/calendar') {
            $driver
                ? $this->sendCalendar($chatId, $driver->id, Carbon::now('Asia/Tashkent'))
                : $this->sendMessage($chatId, "Boshlash uchun /start ni bosing.");
            return;
        }

        // ── Fallback ───────────────────────────────────────────────────────
        $driver
            ? $this->sendMainMenu($chatId, $driver->first_name)
            : $this->sendMessage($chatId, "Boshlash uchun /start ni bosing.");
    }

    // =========================================================================
    // Registration
    // =========================================================================

    private function handleContactRegistration(string $chatId, array $contact): \Illuminate\Http\JsonResponse
    {
        $phone = $contact['phone_number'] ?? '';
        $phone = str_starts_with($phone, '+') ? $phone : '+' . $phone;

        Log::info("DriverGuideBot: phone shared = {$phone}");

        // Try with + prefix, then without
        $driver = Driver::where('phone01', $phone)->orWhere('phone02', $phone)->first();
        $guide  = $driver ? null : Guide::where('phone01', $phone)->orWhere('phone02', $phone)->first();

        if (!$driver && !$guide) {
            $noPlus = ltrim($phone, '+');
            $driver = Driver::where('phone01', $noPlus)->orWhere('phone02', $noPlus)->first();
            $guide  = $driver ? null : Guide::where('phone01', $noPlus)->orWhere('phone02', $noPlus)->first();
        }

        // Try partners table
        if (!$driver && !$guide) {
            $partner = Partner::where('phone', $phone)->orWhere('phone', ltrim($phone, '+'))->first();
            if ($partner) {
                $partner->update(['telegram_chat_id' => $chatId]);
                Log::info("DriverGuideBot: registered partner {$partner->name} → chat_id {$chatId}");
                $this->sendPartnerMenu($chatId,
                    $partner->name,
                    "✅ Rahmat! Siz <b>{$partner->name}</b> sifatida ro'yxatdan o'tdingiz.\n\nEndi bronlar va so'rovlar avtomatik yuboriladi. 🗓️"
                );
                return response()->json(['ok' => true]);
            }
        }

        if (!$driver && !$guide) {
            Log::warning("DriverGuideBot: no match for phone {$phone}");
            $this->sendMessage($chatId,
                "❌ Sizning raqamingiz topilmadi. Iltimos, Odiljon bilan bog'laning: +998 91 555 08 08"
            );
            return response()->json(['ok' => true]);
        }

        if ($driver) {
            $driver->update(['telegram_chat_id' => $chatId]);
            $name = $driver->first_name;
            $type = 'haydovchi (driver)';
        } else {
            $guide->update(['telegram_chat_id' => $chatId]);
            $name = $guide->first_name;
            $type = 'gid (guide)';
        }

        Log::info("DriverGuideBot: registered {$type} {$name} → chat_id {$chatId}");

        // Confirm + set persistent menu (no sleep — just send both quickly)
        $this->sendMessageWithMenu(
            $chatId,
            "✅ Rahmat, <b>{$name}</b>!\n\nSiz {$type} sifatida ro'yxatdan o'tdingiz. Endi tur rejalari avtomatik ravishda sizga yuboriladi. 🗓️\n\nQuyidagi tugmalardan foydalaning:"
        );

        // Auto-show calendar for drivers (no sleep — Telegram queues messages)
        if ($driver) {
            $this->sendCalendar($chatId, $driver->id, Carbon::now('Asia/Tashkent'));
        }

        return response()->json(['ok' => true]);
    }

    // =========================================================================
    // My Bookings
    // =========================================================================

    private function sendMyBookings(string $chatId, int $driverId): void
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
            $pickup = $b->pickup_location ?: 'Samarkand (aniqlanmoqda)';

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

    private function sendCalendar(string $chatId, int $driverId, Carbon $month, ?int $messageId = null): void
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
        $yearEnd   = Carbon::now('Asia/Tashkent')->endOfYear();

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

        $rows[] = [['text' => '✅ Tayyor', 'callback_data' => "CAL|DONE|{$driverId}"]];

        return $rows;
    }

    private function handleCalendarCallback(string $chatId, array $callbackQuery): \Illuminate\Http\JsonResponse
    {
        $messageId = (int) data_get($callbackQuery, 'message.message_id');
        $data      = data_get($callbackQuery, 'data', '');
        $parts     = explode('|', $data);
        $action    = $parts[1] ?? 'NOOP';
        $driverId  = (int) ($parts[2] ?? 0);

        if ($action === 'NOOP') {
            return response()->json(['ok' => true]);
        }

        // ── Partner booking approve/reject ─────────────────────────────────
        if ($action === 'APPROVE' || $action === 'REJECT') {
            return $this->handlePartnerBookingResponse($chatId, $messageId, $action, (int)($parts[2] ?? 0));
        }

        // ── Driver booking confirm/reject ──────────────────────────────────
        if ($action === 'DCONFIRM' || $action === 'DREJECT') {
            return $this->handleDriverBookingResponse($chatId, $messageId, $action, (int)($parts[2] ?? 0), data_get($callbackQuery, 'id', ''));
        }

        // Guard: driverId must be positive
        if ($driverId <= 0) {
            Log::warning('DriverGuideBot: invalid driverId in callback', ['data' => $data]);
            return response()->json(['ok' => true]);
        }

        // Security: verify the chatId owns this driverId
        $ownerCheck = Driver::where('id', $driverId)
            ->where('telegram_chat_id', $chatId)
            ->exists();
        if (!$ownerCheck) {
            Log::warning('DriverGuideBot: chatId mismatch on calendar action', [
                'chatId'   => $chatId,
                'driverId' => $driverId,
                'action'   => $action,
            ]);
            return response()->json(['ok' => true]);
        }

        if ($action === 'TOGGLE' && isset($parts[3])) {
            $date     = $parts[3];
            $monthStr = $parts[4] ?? Carbon::now('Asia/Tashkent')->format('Y-m');

            // Validate date format Y-m-d
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
                Log::warning('DriverGuideBot: invalid date in TOGGLE', compact('date'));
                return response()->json(['ok' => true]);
            }

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
                    'created_at'     => Carbon::now('Asia/Tashkent'),
                    'updated_at'     => Carbon::now('Asia/Tashkent'),
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
            if ($next->lte(Carbon::now('Asia/Tashkent')->endOfYear()->startOfMonth())) {
                $this->sendCalendar($chatId, $driverId, $next, $messageId);
            }
        }

        if ($action === 'DONE') {
            $driver = Driver::find($driverId);
            $name   = $driver?->first_name ?? 'aka';
            $this->editMessageText($chatId, $messageId,
                "✅ Jadval saqlandi! Rahmat, <b>{$name}</b> aka."
            );
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

        $this->sendMessage($this->ownerChatId, implode("\n", $lines));

        Log::warning('DriverGuideBot: conflict detected', [
            'driver_id' => $driverId,
            'date'      => $date,
            'bookings'  => $bookings->count(),
        ]);
    }

    // =========================================================================
    // Partner Methods
    // =========================================================================

    private function sendPartnerMenu(string $chatId, string $name, ?string $intro = null): void
    {
        $text = $intro ?? "👋 Salom, <b>{$name}</b>!\n\nQuyidagi tugmadan foydalaning:";
        try {
            $this->telegramClient->post("/bot{$this->botToken}/sendMessage", [
                'json' => [
                    'chat_id'      => $chatId,
                    'text'         => $text,
                    'parse_mode'   => 'HTML',
                    'reply_markup' => [
                        'keyboard'        => [[['text' => '📋 Bronlarim']]],
                        'resize_keyboard' => true,
                        'persistent'      => true,
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('DriverGuideBot: sendPartnerMenu error', ['error' => $e->getMessage()]);
        }
    }

    private function sendPartnerBookings(string $chatId, Partner $partner): void
    {
        $tourIds = $partner->tour_ids ?? [];
        if (empty($tourIds)) {
            $this->sendMessage($chatId, "📋 Hozircha bronlar yo'q.");
            return;
        }

        $bookings = DB::table('bookings')
            ->join('guests', 'bookings.guest_id', '=', 'guests.id')
            ->join('tours',  'bookings.tour_id',  '=', 'tours.id')
            ->whereIn('bookings.tour_id', $tourIds)
            ->where('bookings.booking_status', 'confirmed')
            ->whereDate('bookings.booking_start_date_time', '>=', Carbon::now('Asia/Tashkent')->toDateString())
            ->orderBy('bookings.booking_start_date_time')
            ->select([
                'bookings.booking_number',
                'bookings.booking_start_date_time',
                'bookings.partner_status',
                'guests.first_name',
                'guests.last_name',
                'guests.number_of_people',
                'tours.title',
            ])
            ->get();

        if ($bookings->isEmpty()) {
            $this->sendMessage($chatId, "📋 Hozircha kelgusi bronlar yo'q.");
            return;
        }

        $lines = ["📋 <b>Kelgusi bronlar:</b>\n"];
        foreach ($bookings as $b) {
            $date    = Carbon::parse($b->booking_start_date_time)->timezone('Asia/Tashkent');
            $pax     = $b->number_of_people ?? 0;
            $status  = match($b->partner_status) {
                'approved' => '✅',
                'rejected' => '❌',
                default    => '⏳',
            };
            $lines[] = "━━━━━━━━━━━━━━━━━━━━";
            $lines[] = "{$status} <b>" . $date->format('D, d M Y') . "</b> — " . $date->format('H:i');
            $lines[] = "👤 {$b->first_name} {$b->last_name} ({$pax} kishi)";
            $lines[] = "🏕 {$b->title}";
            $lines[] = "📋 <code>{$b->booking_number}</code>";
        }
        $lines[] = "━━━━━━━━━━━━━━━━━━━━";

        $this->sendMessage($chatId, implode("\n", $lines));
    }

    public function sendPartnerBookingRequest(int $bookingId): void
    {
        $booking = DB::table('bookings')
            ->join('guests', 'bookings.guest_id', '=', 'guests.id')
            ->join('tours',  'bookings.tour_id',  '=', 'tours.id')
            ->where('bookings.id', $bookingId)
            ->select([
                'bookings.id',
                'bookings.booking_number',
                'bookings.booking_start_date_time',
                'bookings.special_requests',
                'guests.first_name',
                'guests.last_name',
                'guests.number_of_people',
                'guests.country',
                'bookings.tour_id',
                'tours.title as tour_title',
                DB::raw("TIME_FORMAT(bookings.booking_start_date_time,'%H:%i') as pickup_time"),
            ])
            ->first();

        if (!$booking) return;

        // Find partner(s) for this tour and notify all of them
        $tourId = (int) ($booking->tour_id ?? 0);
        $partners = Partner::whereJsonContains('tour_ids', $tourId)->get();

        $validPartners = $partners->filter(fn($p) => !empty($p->telegram_chat_id));
        if ($validPartners->isEmpty()) return;

        $date  = Carbon::parse($booking->booking_start_date_time)->timezone('Asia/Tashkent');
        $pax   = $booking->number_of_people ?? 0;
        $notes = $booking->special_requests ? "\n⚠️ <i>{$booking->special_requests}</i>" : '';

        $text = implode("\n", [
            "🏕 <b>Yangi bron so'rovi — Jahongir Travel</b>",
            "",
            "👤 Mehmon: <b>{$booking->first_name} {$booking->last_name}</b>",
            "📅 Sana: <b>" . $date->format('D, d M Y') . "</b>",
            "👥 Kishi soni: <b>{$pax}</b>",
            "🕗 Kelish: ~{$booking->pickup_time}" . $notes,
            "",
            "Tasdiqlaysizmi?",
        ]);

        $keyboard = [
            [
                ['text' => '✅ Qabul qilish', 'callback_data' => "BOOKING|APPROVE|{$bookingId}"],
                ['text' => '❌ Rad etish',    'callback_data' => "BOOKING|REJECT|{$bookingId}"],
            ],
        ];

        // Notify all partners for this tour (not just the first)
        foreach ($validPartners as $partner) {
            $this->sendInlineKeyboard($partner->telegram_chat_id, $text, $keyboard);

            DB::table('partner_booking_logs')->insert([
                'booking_id'      => $bookingId,
                'partner_id'      => $partner->id,
                'telegram_chat_id'=> $partner->telegram_chat_id,
                'action'          => 'request_sent',
                'booking_number'  => $booking->booking_number,
                'guest_name'      => trim("{$booking->first_name} {$booking->last_name}"),
                'tour_date'       => $date->toDateString(),
                'pax'             => $pax,
                'actioned_at'     => now(),
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }

        // Mark booking as notified
        DB::table('bookings')->where('id', $bookingId)->update([
            'partner_status'      => 'pending',
            'partner_notified_at' => now(),
        ]);
    }

    private function handlePartnerBookingResponse(
        string $chatId, int $messageId, string $action, int $bookingId
    ): \Illuminate\Http\JsonResponse {
        // Verify this partner manages this booking's tour
        $partner = Partner::where('telegram_chat_id', $chatId)->first();
        if (!$partner) return response()->json(['ok' => true]);

        $booking = DB::table('bookings')
            ->join('guests', 'bookings.guest_id', '=', 'guests.id')
            ->where('bookings.id', $bookingId)
            ->select(['bookings.id', 'bookings.tour_id', 'bookings.booking_number', 'guests.first_name', 'guests.last_name'])
            ->first();

        if (!$booking || !$partner->managesTour((int) $booking->tour_id)) {
            return response()->json(['ok' => true]);
        }

        $status  = $action === 'APPROVE' ? 'approved' : 'rejected';
        $emoji   = $action === 'APPROVE' ? '✅' : '❌';
        $label   = $action === 'APPROVE' ? 'Qabul qilindi' : 'Rad etildi';

        $now = now();
        DB::table('bookings')->where('id', $bookingId)->update(['partner_status' => $status]);

        // Audit log: response recorded with full snapshot
        DB::table('partner_booking_logs')->insert([
            'booking_id'       => $bookingId,
            'partner_id'       => $partner->id,
            'telegram_chat_id' => $chatId,
            'action'           => $status, // 'approved' or 'rejected'
            'booking_number'   => $booking->booking_number,
            'guest_name'       => trim("{$booking->first_name} {$booking->last_name}"),
            'tour_date'        => null, // already in booking record
            'pax'              => null,
            'actioned_at'      => $now,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        // Edit the request message to show result + timestamp
        $this->editMessageText($chatId, $messageId,
            "{$emoji} <b>{$label}</b>\n\n"
            . "👤 {$booking->first_name} {$booking->last_name}\n"
            . "📋 {$booking->booking_number}\n"
            . "🕐 " . $now->timezone('Asia/Tashkent')->format('d M Y, H:i') . " (Toshkent vaqti)"
        );

        // Notify owner with full detail + timestamp
        $ownerText = "{$emoji} <b>Yurt Camp javobi: {$label}</b>\n\n"
            . "👤 {$booking->first_name} {$booking->last_name}\n"
            . "📋 {$booking->booking_number}\n"
            . "🏕 {$partner->name}\n"
            . "🕐 " . $now->timezone('Asia/Tashkent')->format('d M Y, H:i') . " (Toshkent)";
        $this->sendMessage($this->ownerChatId, $ownerText);

        Log::info("DriverGuideBot: partner {$action} booking {$bookingId}", [
            'partner'    => $partner->name,
            'status'     => $status,
            'chat_id'    => $chatId,
            'actioned_at'=> $now->toIso8601String(),
        ]);

        return response()->json(['ok' => true]);
    }

    // =========================================================================
    // Menus
    // =========================================================================

    private function sendMainMenu(string $chatId, string $name): void
    {
        $this->sendMessageWithMenu(
            $chatId,
            "👋 Salom, <b>{$name}</b> aka!\n\nQuyidagi tugmalardan foydalaning:"
        );
    }

    /**
     * Send a message with the persistent bottom keyboard.
     * This keyboard stays visible until explicitly removed.
     */
    private function sendMessageWithMenu(string $chatId, string $text): void
    {
        try {
            $this->telegramClient->post("/bot{$this->botToken}/sendMessage", [
                'json' => [
                    'chat_id'      => $chatId,
                    'text'         => $text,
                    'parse_mode'   => 'HTML',
                    'reply_markup' => [
                        'keyboard'        => [
                            [
                                ['text' => '📋 Mening bronlarim'],
                                ['text' => '📅 Mening jadvalim'],
                            ],
                        ],
                        'resize_keyboard' => true,
                        'persistent'      => true,
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

    private function sendMessage(string $chatId, string $text): void
    {
        try {
            $this->telegramClient->post("/bot{$this->botToken}/sendMessage", [
                'json' => ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'],
            ]);
        } catch (\Exception $e) {
            Log::error('DriverGuideBot: sendMessage error', ['error' => $e->getMessage()]);
        }
    }

    private function sendInlineKeyboard(string $chatId, string $text, array $keyboard): void
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

    private function editMessage(string $chatId, int $messageId, string $text, array $keyboard): void
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

    private function editMessageText(string $chatId, int $messageId, string $text): void
    {
        try {
            $this->telegramClient->post("/bot{$this->botToken}/editMessageText", [
                'json' => ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text, 'parse_mode' => 'HTML'],
            ]);
        } catch (\Exception $e) {
            Log::error('DriverGuideBot: editMessageText error', ['error' => $e->getMessage()]);
        }
    }

    private function sendContactRequest(string $chatId, string $prompt): void
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

    // =========================================================================
    // Driver Booking Request / Confirm
    // =========================================================================

    /**
     * Offer a booking to a driver WITHOUT setting driver_id in DB.
     * driver_id is only set when the driver taps ✅ (confirm-before-assign).
     */
    public function sendDriverBookingOffer(int $bookingId, int $driverId): void
    {
        $booking = DB::table('bookings')
            ->join('guests', 'bookings.guest_id', '=', 'guests.id')
            ->join('tours',  'bookings.tour_id',  '=', 'tours.id')
            ->where('bookings.id', $bookingId)
            ->select([
                'bookings.id',
                'bookings.booking_number',
                'bookings.booking_start_date_time',
                'bookings.pickup_location',
                'bookings.tour_id',
                'guests.first_name',
                'guests.last_name',
                'guests.number_of_people',
                'guests.country',
                'tours.title as tour_title',
                'tours.driver_route as tour_driver_route',
                DB::raw("TIME_FORMAT(bookings.booking_start_date_time,'%H:%i') as pickup_time"),
            ])
            ->first();

        if (!$booking) return;

        $driver = DB::table('drivers')->where('id', $driverId)->first();
        if (!$driver || !$driver->telegram_chat_id) return;

        $date    = Carbon::parse($booking->booking_start_date_time)->timezone('Asia/Tashkent');
        $pax     = $booking->number_of_people ?? 0;
        $pickup  = $booking->pickup_location ?: 'Samarkand (aniqlanmoqda)';
        $route   = $booking->tour_driver_route ? "\n🗺 {$booking->tour_driver_route}" : '';

        $text = implode("\n", [
            "🚗 <b>Yangi tur tayinlandi!</b>",
            "",
            "🏕 <b>{$booking->tour_title}</b>",
            "📅 " . $date->format('D, d M Y') . " — 🕗 {$booking->pickup_time}",
            "👤 {$booking->first_name} {$booking->last_name} — {$pax} kishi",
            "🏨 {$pickup}" . $route,
            "📋 <code>{$booking->booking_number}</code>",
            "",
            "Tasdiqlaysizmi?",
        ]);

        $keyboard = [[
            ['text' => '✅ Qabul',   'callback_data' => "BOOKING|DCONFIRM|{$bookingId}"],
            ['text' => '❌ Rad etish', 'callback_data' => "BOOKING|DREJECT|{$bookingId}"],
        ]];

        $this->sendInlineKeyboard($driver->telegram_chat_id, $text, $keyboard);

        $now = now();
        // NOTE: driver_id is NOT set on bookings yet — only set when driver confirms

        DB::table('driver_booking_logs')->insert([
            'booking_id'       => $bookingId,
            'driver_id'        => $driver->id,
            'telegram_chat_id' => $driver->telegram_chat_id,
            'action'           => 'offer_sent',
            'booking_number'   => $booking->booking_number,
            'guest_name'       => trim("{$booking->first_name} {$booking->last_name}"),
            'tour_date'        => $date->toDateString(),
            'pax'              => $pax,
            'actioned_at'      => $now,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        Log::info("DriverGuideBot: booking offer sent to driver (not yet assigned)", [
            'driver_id'  => $driver->id,
            'booking_id' => $bookingId,
        ]);
    }

    private function handleDriverBookingResponse(
        string $chatId, int $messageId, string $action, int $bookingId, string $callbackQueryId = ''
    ): \Illuminate\Http\JsonResponse {
        $driver = Driver::where('telegram_chat_id', $chatId)->first();
        if (!$driver) return response()->json(['ok' => true]);

        // Security: verify this driver was offered this booking
        $wasOffered = DB::table('driver_booking_logs')
            ->where('booking_id', $bookingId)
            ->where('driver_id', $driver->id)
            ->whereIn('action', ['offer_sent', 'request_sent'])
            ->exists();
        if (!$wasOffered) return response()->json(['ok' => true]);

        $booking = DB::table('bookings')
            ->join('guests', 'bookings.guest_id', '=', 'guests.id')
            ->where('bookings.id', $bookingId)
            ->select(['bookings.id', 'bookings.booking_number', 'guests.first_name', 'guests.last_name', 'guests.number_of_people'])
            ->first();

        if (!$booking) return response()->json(['ok' => true]);

        $status = $action === 'DCONFIRM' ? 'confirmed' : 'rejected';
        $emoji  = $action === 'DCONFIRM' ? '✅' : '❌';
        $label  = $action === 'DCONFIRM' ? 'Tasdiqlandi' : 'Rad etildi';
        $now    = now();

        // Atomic: lock booking row, recheck idempotency, then write
        $alreadyDone = false;
        DB::transaction(function () use ($bookingId, $driver, $chatId, $action, $status, $booking, $now, &$alreadyDone) {
            // Lock the booking row to prevent double-assign race
            $lockedBooking = DB::table('bookings')->where('id', $bookingId)->lockForUpdate()->first();
            if (!$lockedBooking) return;

            // Idempotency: ignore if already actioned by this driver
            $alreadyActioned = DB::table('driver_booking_logs')
                ->where('booking_id', $bookingId)
                ->where('driver_id', $driver->id)
                ->whereIn('action', ['confirmed', 'rejected'])
                ->exists();
            if ($alreadyActioned) { $alreadyDone = true; return; }

            // Also reject if another driver already confirmed this booking
            if ($action === 'DCONFIRM' && $lockedBooking->driver_id && $lockedBooking->driver_status === 'confirmed') {
                $alreadyDone = true;
                return;
            }

            if ($action === 'DCONFIRM') {
                DB::table('bookings')->where('id', $bookingId)->update([
                    'driver_id'          => $driver->id,
                    'driver_status'      => 'confirmed',
                    'driver_notified_at' => $now,
                ]);
            } else {
                DB::table('bookings')->where('id', $bookingId)->update([
                    'driver_status' => 'rejected',
                ]);
            }

            // Audit log inside transaction
            DB::table('driver_booking_logs')->insert([
                'booking_id'       => $bookingId,
                'driver_id'        => $driver->id,
                'telegram_chat_id' => $chatId,
                'action'           => $status,
                'booking_number'   => $booking->booking_number,
                'guest_name'       => trim("{$booking->first_name} {$booking->last_name}"),
                'tour_date'        => null,
                'pax'              => $booking->number_of_people,
                'actioned_at'      => $now,
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);
        }); // end DB::transaction

        if ($alreadyDone) {
            $this->answerCallbackQuery($callbackQueryId ?? '');
            return response()->json(['ok' => true]);
        }

        // Edit message — show result + timestamp (outside transaction)
        $this->editMessageText($chatId, $messageId,
            "{$emoji} <b>{$label}</b>\n\n"
            . "👤 {$booking->first_name} {$booking->last_name}\n"
            . "📋 {$booking->booking_number}\n"
            . "🕐 " . $now->timezone('Asia/Tashkent')->format('d M Y, H:i') . " (Toshkent)"
        );

        // Notify owner
        $driverName = trim("{$driver->first_name} {$driver->last_name}");
        $pax        = $booking->number_of_people ?? 0;
        $this->sendMessage($this->ownerChatId,
            "{$emoji} <b>Haydovchi javobi: {$label}</b>\n\n"
            . "🚗 {$driverName}\n"
            . "👤 {$booking->first_name} {$booking->last_name} ({$pax} pax)\n"
            . "📋 {$booking->booking_number}\n"
            . "🕐 " . $now->timezone('Asia/Tashkent')->format('d M Y, H:i') . " (Toshkent)"
        );

        Log::info("DriverGuideBot: driver {$action} booking {$bookingId}", [
            'driver_id'   => $driver->id,
            'status'      => $status,
            'actioned_at' => $now->toIso8601String(),
        ]);

        return response()->json(['ok' => true]);
    }

    private function answerCallbackQuery(string $callbackQueryId): void
    {
        try {
            $this->telegramClient->post("/bot{$this->botToken}/answerCallbackQuery", [
                'json' => ['callback_query_id' => $callbackQueryId],
            ]);
        } catch (\Exception $e) {
            // silent — not critical
        }
    }
}
