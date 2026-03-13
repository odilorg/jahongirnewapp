<?php

namespace App\Http\Controllers;

use App\Models\KitchenMealCount;
use App\Models\TelegramPosSession;
use App\Models\User;
use App\Services\KitchenGuestService;
use App\Services\OwnerAlertService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class KitchenBotController extends Controller
{
    protected string $botToken;
    protected OwnerAlertService $ownerAlert;
    protected KitchenGuestService $kitchen;

    public function __construct(OwnerAlertService $ownerAlert, KitchenGuestService $kitchen)
    {
        $this->botToken   = config('services.kitchen_bot.token', '');
        $this->ownerAlert = $ownerAlert;
        $this->kitchen    = $kitchen;
    }

    // ── WEBHOOK ENTRY ────────────────────────────────────────────

    public function handleWebhook(Request $request)
    {
        try {
            Log::debug('KitchenBot webhook', ['data' => $request->all()]);

            if ($cb = $request->input('callback_query')) {
                return $this->handleCallback($cb);
            }

            if ($message = $request->input('message')) {
                return $this->handleMessage($message);
            }

            return response('OK');
        } catch (\Throwable $e) {
            Log::error('KitchenBot unhandled error', [
                'e'     => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->alertOwnerOnError('Webhook', $e);
            return response('OK');
        }
    }

    // ── MESSAGE HANDLER ──────────────────────────────────────────

    protected function handleMessage(array $message)
    {
        $chatId  = $message['chat']['id'] ?? null;
        $text    = trim($message['text'] ?? '');
        $contact = $message['contact'] ?? null;

        if (!$chatId) return response('OK');

        // Auth: phone contact
        if ($contact) return $this->handleAuth($chatId, $contact);

        $session = $this->getSession($chatId);

        // Not authenticated
        if (!$session || !$session->user_id) {
            $this->send($chatId, "👋 Oshxona botiga xush kelibsiz!\n\nTelefon raqamingizni yuboring.", $this->phoneKb());
            return response('OK');
        }

        $session->updateActivity();

        $today = now()->timezone('Asia/Tashkent')->format('Y-m-d');

        // State-based: waiting for date input
        if ($session->state === 'kitchen_date_input') {
            return $this->handleDateInput($chatId, $session, $text);
        }

        // Button handlers
        if ($text === '/start' || $text === '🏠 Bosh sahifa') return $this->showWelcome($chatId, $session);

        if ($text === '➕ 1 mehmon' || $text === '/plus') return $this->incrementServed($chatId, $session, 1);
        if ($text === '➕ 2') return $this->incrementServed($chatId, $session, 2);
        if ($text === '➕ 3') return $this->incrementServed($chatId, $session, 3);
        if ($text === '➖ Qaytarish' || $text === '/minus') return $this->decrementServed($chatId, $session, 1);

        if ($text === '📊 Qoldiq' || $text === '/remaining') return $this->showRemaining($chatId, $today);
        if ($text === '🍳 Bugun jami' || $text === '/today') return $this->showTodayFull($chatId, $today);
        if ($text === '📊 Haftalik' || $text === '/week') return $this->showWeekly($chatId);
        if ($text === '📅 Sana tanlash' || $text === '/date') {
            $session->update(['state' => 'kitchen_date_input', 'data' => null]);
            $this->send($chatId, "📅 Sanani kiriting:\n\nMasalan: <code>15.03</code> yoki <code>2026-03-15</code>", $this->mainKb());
            return response('OK');
        }
        if ($text === '🔄 Yangilash' || $text === '/refresh') return $this->refreshCount($chatId, $today);
        if ($text === '❓ Yordam' || $text === '/help') return $this->showHelp($chatId);

        if ($text === '🚪 Chiqish' || $text === '/logout') {
            $session->update(['user_id' => null, 'state' => 'idle', 'data' => null]);
            $this->send($chatId, "Chiqildi. Qayta kirish uchun telefon raqamingizni yuboring.", $this->phoneKb());
            return response('OK');
        }

        // Try parsing as a number (quick +N)
        if (preg_match('/^\+?(\d{1,2})$/', $text, $m)) {
            $count = (int) $m[1];
            if ($count >= 1 && $count <= 20) {
                return $this->incrementServed($chatId, $session, $count);
            }
        }

        // Unknown input
        $this->send($chatId, "Tugmalardan birini tanlang yoki mehmon sonini yozing (masalan: <code>3</code>)", $this->mainKb());
        return response('OK');
    }

    // ── CALLBACK QUERY HANDLER ─────────────────────────────────

    protected function handleCallback(array $cb)
    {
        $chatId = $cb['message']['chat']['id'] ?? null;
        $data   = $cb['data'] ?? '';
        $cbId   = $cb['id'] ?? '';

        if (!$chatId) return response('OK');

        $this->aCb($cbId);

        // Inline callbacks for quick +1, +2, +3, -1
        if ($data === 'k_plus_1') return $this->incrementServedInline($chatId, 1, $cb['message']['message_id'] ?? null);
        if ($data === 'k_plus_2') return $this->incrementServedInline($chatId, 2, $cb['message']['message_id'] ?? null);
        if ($data === 'k_plus_3') return $this->incrementServedInline($chatId, 3, $cb['message']['message_id'] ?? null);
        if ($data === 'k_minus_1') return $this->decrementServedInline($chatId, 1, $cb['message']['message_id'] ?? null);
        if ($data === 'k_refresh') return $this->refreshCountInline($chatId, $cb['message']['message_id'] ?? null);

        return response('OK');
    }

    // ── AUTH ──────────────────────────────────────────────────────

    protected function handleAuth(int $chatId, array $contact)
    {
        $phone = preg_replace('/[^0-9]/', '', $contact['phone_number'] ?? '');
        $user  = User::where('phone_number', 'LIKE', '%' . substr($phone, -9))->first();

        if (!$user) {
            $this->send($chatId, "❌ Raqam topilmadi. Rahbariyatga murojaat qiling.");
            return response('OK');
        }

        // Use negative chat_id to avoid session collision with other bots
        $sessionChatId = $this->sessionChatId($chatId);

        TelegramPosSession::updateOrCreate(
            ['chat_id' => $sessionChatId],
            ['telegram_user_id' => $chatId, 'user_id' => $user->id, 'state' => 'kitchen_main', 'data' => null]
        );

        $this->send($chatId, "✅ Xush kelibsiz, {$user->name}!");
        return $this->showWelcome($chatId, $this->getSession($chatId));
    }

    /**
     * Get session for this kitchen bot user.
     * Uses negative chat_id to avoid collision with housekeeping/cashier bots.
     */
    protected function getSession(int $chatId): ?TelegramPosSession
    {
        return TelegramPosSession::where('chat_id', $this->sessionChatId($chatId))->first();
    }

    /**
     * Offset chat_id for kitchen bot sessions to avoid collision.
     * Housekeeping uses positive chat_id, kitchen uses negative.
     */
    protected function sessionChatId(int $chatId): int
    {
        return -abs($chatId);
    }

    // ── WELCOME ──────────────────────────────────────────────────

    protected function showWelcome(int $chatId, $session)
    {
        $session->update(['state' => 'kitchen_main', 'data' => null]);

        $user = User::find($session->user_id);
        $name = $user?->name ?? '';

        $today = now()->timezone('Asia/Tashkent')->format('Y-m-d');

        // Auto-sync today's count
        $meal = $this->kitchen->syncExpectedCount($today);

        $text = "🍳 <b>Jahongir Hotel — Oshxona Bot</b>\n\n"
            . "Salom, <b>{$name}</b>! 👋\n\n"
            . "📌 <b>Bugungi nonushta:</b>\n"
            . "👥 Jami kutilmoqda: <b>{$meal->total_expected}</b>"
            . ($meal->total_children > 0 ? " ({$meal->total_adults} katta + {$meal->total_children} bola)" : '') . "\n"
            . "✅ Keldi: <b>{$meal->served_count}</b>\n"
            . "⏳ Qoldi: <b>{$meal->remaining()}</b>\n\n"
            . "📌 <b>Qanday ishlaydi:</b>\n"
            . "• Mehmon kelganda <b>➕ 1 mehmon</b> bosing\n"
            . "• Xato bo'lsa <b>➖ Qaytarish</b> bosing\n"
            . "• Sonni yozish ham mumkin: <code>3</code> = 3 mehmon\n"
            . "• <b>📊 Qoldiq</b> — qancha mehmon kelmagan";

        $this->send($chatId, $text, $this->mainKb());
        return response('OK');
    }

    // ── LIVE COUNTER: INCREMENT ─────────────────────────────────

    protected function incrementServed(int $chatId, $session, int $count)
    {
        $today = now()->timezone('Asia/Tashkent')->format('Y-m-d');
        $meal = KitchenMealCount::forDate($today);

        if (!$meal) {
            $meal = $this->kitchen->syncExpectedCount($today);
        }

        $meal->incrementServed($count);
        $meal->refresh();

        $emoji = $count === 1 ? '✅' : "✅ +{$count}";
        $text = "{$emoji} Keldi: <b>{$meal->served_count}</b> / {$meal->total_expected}\n"
            . "⏳ Qoldi: <b>{$meal->remaining()}</b>";

        $this->send($chatId, $text, $this->mainKb());
        return response('OK');
    }

    protected function incrementServedInline(int $chatId, int $count, ?int $messageId)
    {
        $today = now()->timezone('Asia/Tashkent')->format('Y-m-d');
        $meal = KitchenMealCount::forDate($today);

        if (!$meal) {
            $meal = $this->kitchen->syncExpectedCount($today);
        }

        $meal->incrementServed($count);
        $meal->refresh();

        $text = "✅ Keldi: <b>{$meal->served_count}</b> / {$meal->total_expected}\n"
            . "⏳ Qoldi: <b>{$meal->remaining()}</b>";

        if ($messageId) {
            $this->editMessage($chatId, $messageId, $text, $this->counterInlineKb());
        } else {
            $this->send($chatId, $text, $this->mainKb());
        }
        return response('OK');
    }

    // ── LIVE COUNTER: DECREMENT ─────────────────────────────────

    protected function decrementServed(int $chatId, $session, int $count)
    {
        $today = now()->timezone('Asia/Tashkent')->format('Y-m-d');
        $meal = KitchenMealCount::forDate($today);

        if (!$meal) {
            $this->send($chatId, "Bugun hali ma'lumot yo'q. 🔄 Yangilash bosing.", $this->mainKb());
            return response('OK');
        }

        $meal->decrementServed($count);
        $meal->refresh();

        $text = "↩️ Qaytarildi. Keldi: <b>{$meal->served_count}</b> / {$meal->total_expected}\n"
            . "⏳ Qoldi: <b>{$meal->remaining()}</b>";

        $this->send($chatId, $text, $this->mainKb());
        return response('OK');
    }

    protected function decrementServedInline(int $chatId, int $count, ?int $messageId)
    {
        $today = now()->timezone('Asia/Tashkent')->format('Y-m-d');
        $meal = KitchenMealCount::forDate($today);

        if (!$meal) return response('OK');

        $meal->decrementServed($count);
        $meal->refresh();

        $text = "↩️ Qaytarildi. Keldi: <b>{$meal->served_count}</b> / {$meal->total_expected}\n"
            . "⏳ Qoldi: <b>{$meal->remaining()}</b>";

        if ($messageId) {
            $this->editMessage($chatId, $messageId, $text, $this->counterInlineKb());
        } else {
            $this->send($chatId, $text, $this->mainKb());
        }
        return response('OK');
    }

    // ── SHOW REMAINING ──────────────────────────────────────────

    protected function showRemaining(int $chatId, string $date)
    {
        $meal = KitchenMealCount::forDate($date);

        if (!$meal) {
            $meal = $this->kitchen->syncExpectedCount($date);
        }

        $pct = $meal->total_expected > 0
            ? round(($meal->served_count / $meal->total_expected) * 100)
            : 0;

        // Visual progress bar
        $filled = (int) round($pct / 10);
        $bar = str_repeat('▓', $filled) . str_repeat('░', 10 - $filled);

        $text = "📊 <b>Bugungi nonushta holati</b>\n\n"
            . "👥 Jami kutilmoqda: <b>{$meal->total_expected}</b>\n";

        if ($meal->total_children > 0) {
            $text .= "   👨 Kattalar: {$meal->total_adults}\n"
                . "   👶 Bolalar: {$meal->total_children}\n";
        }

        $text .= "\n✅ Keldi: <b>{$meal->served_count}</b>\n"
            . "⏳ Qoldi: <b>{$meal->remaining()}</b>\n\n"
            . "[{$bar}] {$pct}%";

        $this->sendWithInline($chatId, $text, $this->counterInlineKb());
        return response('OK');
    }

    // ── SHOW TODAY FULL DETAILS ─────────────────────────────────

    protected function showTodayFull(int $chatId, string $date)
    {
        $counts = $this->kitchen->getGuestCountForDate($date);
        $meal = $this->kitchen->syncExpectedCount($date);
        $bd = $counts['breakdown'] ?? [];

        $dateObj = Carbon::parse($date);
        $dayLabel = $dateObj->isToday() ? 'Bugun' : $dateObj->format('d.m (D)');

        $text = "🍳 <b>{$dayLabel} — Nonushta hisobi</b>\n\n"
            . "👥 Jami mehmonlar: <b>{$counts['total']}</b>\n";

        if ($counts['children'] > 0) {
            $text .= "   👨 Kattalar: {$counts['adults']}\n"
                . "   👶 Bolalar: {$counts['children']}\n";
        }

        $text .= "🏨 Jami bronlar: {$counts['bookings']}\n\n";

        if (!empty($bd)) {
            $text .= "📋 <b>Tafsilot:</b>\n";
            if ($bd['stayovers'] > 0) $text .= "  🛏 Qolayotgan: {$bd['stayovers']} bron\n";
            if ($bd['departures'] > 0) $text .= "  🚪 Ketayotgan: {$bd['departures']} bron\n";
            if ($bd['arrivals'] > 0) $text .= "  🛬 Kelayotgan: {$bd['arrivals']} bron\n";
            $text .= "\n";
        }

        $text .= "━━━━━━━━━━━━━━━━━━\n"
            . "✅ Keldi: <b>{$meal->served_count}</b>\n"
            . "⏳ Qoldi: <b>{$meal->remaining()}</b>";

        $this->send($chatId, $text, $this->mainKb());
        return response('OK');
    }

    // ── WEEKLY FORECAST ─────────────────────────────────────────

    protected function showWeekly(int $chatId)
    {
        $this->send($chatId, "⏳ Haftalik prognoz tayyorlanmoqda...");

        $forecast = $this->kitchen->getWeeklyForecast();

        $lines = ["📊 <b>7 kunlik mehmon prognozi</b>\n"];

        $today = now()->timezone('Asia/Tashkent')->format('Y-m-d');

        foreach ($forecast as $day) {
            $isToday = $day['date'] === $today;
            $prefix = $isToday ? '▶️' : '  ';
            $label = $isToday ? '<b>Bugun</b>' : $day['day_label'] . ' ' . $day['day_name'];

            $childInfo = $day['children'] > 0 ? " ({$day['adults']}+{$day['children']}🧒)" : '';

            $lines[] = "{$prefix} {$label}: 👥 <b>{$day['total']}</b>{$childInfo}";
        }

        $lines[] = "\n💡 <i>Mahsulot harid qilish uchun foydalaning</i>";

        $this->send($chatId, implode("\n", $lines), $this->mainKb());
        return response('OK');
    }

    // ── DATE INPUT ──────────────────────────────────────────────

    protected function handleDateInput(int $chatId, $session, string $text)
    {
        if ($text === '/cancel' || $text === '❌ Bekor qilish') {
            $session->update(['state' => 'kitchen_main', 'data' => null]);
            $this->send($chatId, "Bekor qilindi.", $this->mainKb());
            return response('OK');
        }

        // Parse various date formats
        $date = $this->parseDate($text);

        if (!$date) {
            $this->send($chatId, "❌ Sana formatini tushunmadim.\n\nMasalan: <code>15.03</code> yoki <code>2026-03-15</code>");
            return response('OK');
        }

        $session->update(['state' => 'kitchen_main', 'data' => null]);

        $counts = $this->kitchen->getGuestCountForDate($date);
        $dateObj = Carbon::parse($date);
        $dayLabel = $dateObj->format('d.m.Y (D)');

        $text = "📅 <b>{$dayLabel}</b>\n\n"
            . "👥 Kutilayotgan mehmonlar: <b>{$counts['total']}</b>\n";

        if ($counts['children'] > 0) {
            $text .= "   👨 Kattalar: {$counts['adults']}\n"
                . "   👶 Bolalar: {$counts['children']}\n";
        }

        $text .= "🏨 Bronlar: {$counts['bookings']}\n";

        $bd = $counts['breakdown'] ?? [];
        if (!empty($bd)) {
            $text .= "\n📋 Tafsilot:\n";
            if ($bd['stayovers'] > 0) $text .= "  🛏 Qolayotgan: {$bd['stayovers']}\n";
            if ($bd['departures'] > 0) $text .= "  🚪 Ketayotgan: {$bd['departures']}\n";
            if ($bd['arrivals'] > 0) $text .= "  🛬 Kelayotgan: {$bd['arrivals']}\n";
        }

        $this->send($chatId, $text, $this->mainKb());
        return response('OK');
    }

    // ── REFRESH COUNT ───────────────────────────────────────────

    protected function refreshCount(int $chatId, string $date)
    {
        $meal = $this->kitchen->syncExpectedCount($date);

        $text = "🔄 Yangilandi!\n\n"
            . "👥 Jami kutilmoqda: <b>{$meal->total_expected}</b>\n"
            . "✅ Keldi: <b>{$meal->served_count}</b>\n"
            . "⏳ Qoldi: <b>{$meal->remaining()}</b>";

        $this->send($chatId, $text, $this->mainKb());
        return response('OK');
    }

    protected function refreshCountInline(int $chatId, ?int $messageId)
    {
        $today = now()->timezone('Asia/Tashkent')->format('Y-m-d');
        $meal = $this->kitchen->syncExpectedCount($today);

        $text = "🔄 Yangilandi!\n\n"
            . "👥 Jami: <b>{$meal->total_expected}</b>\n"
            . "✅ Keldi: <b>{$meal->served_count}</b>\n"
            . "⏳ Qoldi: <b>{$meal->remaining()}</b>";

        if ($messageId) {
            $this->editMessage($chatId, $messageId, $text, $this->counterInlineKb());
        } else {
            $this->send($chatId, $text, $this->mainKb());
        }
        return response('OK');
    }

    // ── HELP ────────────────────────────────────────────────────

    protected function showHelp(int $chatId)
    {
        $text = "❓ <b>Oshxona Bot — Yordam</b>\n\n"
            . "🍳 Bu bot nonushta mehmonlarini hisoblash uchun.\n\n"
            . "<b>Asosiy tugmalar:</b>\n"
            . "• <b>➕ 1 mehmon</b> — mehmon kelganda bosing\n"
            . "• <b>➕ 2 / ➕ 3</b> — bir nechta mehmon kelganda\n"
            . "• <b>➖ Qaytarish</b> — xato bo'lsa, 1 ta qaytarish\n"
            . "• <b>📊 Qoldiq</b> — qancha mehmon hali kelmagan\n"
            . "• <b>🍳 Bugun jami</b> — to'liq hisobot\n"
            . "• <b>📊 Haftalik</b> — 7 kunlik prognoz\n"
            . "• <b>📅 Sana tanlash</b> — istalgan kunga prognoz\n"
            . "• <b>🔄 Yangilash</b> — Beds24 dan yangi ma'lumot\n\n"
            . "<b>Qo'shimcha:</b>\n"
            . "• Raqam yozish = shu sondagi mehmon qo'shish\n"
            . "  Masalan: <code>5</code> = 5 mehmon keldi\n\n"
            . "💡 Har kuni ertalab bot avtomatik yangilanadi.";

        $this->send($chatId, $text, $this->mainKb());
        return response('OK');
    }

    // ── KEYBOARDS ───────────────────────────────────────────────

    protected function mainKb(): array
    {
        return [
            'keyboard' => [
                [['text' => '➕ 1 mehmon'], ['text' => '➕ 2'], ['text' => '➕ 3']],
                [['text' => '➖ Qaytarish'], ['text' => '📊 Qoldiq']],
                [['text' => '🍳 Bugun jami'], ['text' => '📊 Haftalik']],
                [['text' => '📅 Sana tanlash'], ['text' => '🔄 Yangilash']],
                [['text' => '❓ Yordam'], ['text' => '🚪 Chiqish']],
            ],
            'resize_keyboard' => true,
        ];
    }

    protected function phoneKb(): array
    {
        return [
            'keyboard'          => [[['text' => 'Telefon raqamni yuborish', 'request_contact' => true]]],
            'resize_keyboard'   => true,
            'one_time_keyboard' => true,
        ];
    }

    protected function counterInlineKb(): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '➕ 1', 'callback_data' => 'k_plus_1'],
                    ['text' => '➕ 2', 'callback_data' => 'k_plus_2'],
                    ['text' => '➕ 3', 'callback_data' => 'k_plus_3'],
                    ['text' => '➖ 1', 'callback_data' => 'k_minus_1'],
                ],
                [
                    ['text' => '🔄 Yangilash', 'callback_data' => 'k_refresh'],
                ],
            ],
        ];
    }

    // ── HELPERS ──────────────────────────────────────────────────

    protected function parseDate(string $input): ?string
    {
        $input = trim($input);

        // Format: 15.03 or 15.03.2026
        if (preg_match('/^(\d{1,2})\.(\d{1,2})(?:\.(\d{4}))?$/', $input, $m)) {
            $year = $m[3] ?? now()->year;
            try {
                return Carbon::createFromDate($year, $m[2], $m[1])->format('Y-m-d');
            } catch (\Throwable $e) {
                return null;
            }
        }

        // Format: 2026-03-15
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $input)) {
            try {
                return Carbon::parse($input)->format('Y-m-d');
            } catch (\Throwable $e) {
                return null;
            }
        }

        // Format: 15/03 or 15/03/2026
        if (preg_match('/^(\d{1,2})\/(\d{1,2})(?:\/(\d{4}))?$/', $input, $m)) {
            $year = $m[3] ?? now()->year;
            try {
                return Carbon::createFromDate($year, $m[2], $m[1])->format('Y-m-d');
            } catch (\Throwable $e) {
                return null;
            }
        }

        return null;
    }

    protected function send(int $chatId, string $text, ?array $kb = null): void
    {
        $p = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'];
        if ($kb) $p['reply_markup'] = json_encode($kb);

        try {
            $resp = Http::timeout(10)->post(
                "https://api.telegram.org/bot{$this->botToken}/sendMessage",
                $p
            );
            if (!$resp->successful()) {
                Log::warning('KitchenBot send failed', [
                    'chat'   => $chatId,
                    'status' => $resp->status(),
                    'body'   => $resp->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('KitchenBot send error', ['chat' => $chatId, 'error' => $e->getMessage()]);
        }
    }

    protected function sendWithInline(int $chatId, string $text, array $inlineKb): void
    {
        $p = [
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'HTML',
            'reply_markup' => json_encode($inlineKb),
        ];

        try {
            Http::timeout(10)->post(
                "https://api.telegram.org/bot{$this->botToken}/sendMessage",
                $p
            );
        } catch (\Throwable $e) {
            Log::error('KitchenBot sendWithInline error', ['chat' => $chatId, 'error' => $e->getMessage()]);
        }
    }

    protected function editMessage(int $chatId, int $messageId, string $text, ?array $inlineKb = null): void
    {
        $p = [
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ];
        if ($inlineKb) $p['reply_markup'] = json_encode($inlineKb);

        try {
            Http::timeout(10)->post(
                "https://api.telegram.org/bot{$this->botToken}/editMessageText",
                $p
            );
        } catch (\Throwable $e) {
            Log::error('KitchenBot editMessage error', ['chat' => $chatId, 'error' => $e->getMessage()]);
        }
    }

    protected function aCb(string $id): void
    {
        if (!$id) return;
        Http::post(
            "https://api.telegram.org/bot{$this->botToken}/answerCallbackQuery",
            ['callback_query_id' => $id]
        );
    }

    protected function alertOwnerOnError(string $context, \Throwable $e, ?int $userId = null): void
    {
        try {
            $user = $userId ? (User::find($userId)?->name ?? "ID:{$userId}") : 'unknown';
            $msg  = "🔴 <b>Kitchen Bot Error</b>\n\n"
                . "📍 {$context}\n"
                . "👤 {$user}\n"
                . "❌ " . mb_substr($e->getMessage(), 0, 200) . "\n"
                . "📄 " . basename($e->getFile()) . ":" . $e->getLine();
            $this->ownerAlert->sendShiftCloseReport($msg);
        } catch (\Throwable $ignore) {}
    }
}
