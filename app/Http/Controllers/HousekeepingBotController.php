<?php

namespace App\Http\Controllers;

use App\Jobs\SendTelegramNotificationJob;
use App\Models\LostFoundItem;
use App\Models\RoomIssue;
use App\Models\TelegramPosSession;
use App\Models\User;
use App\Services\Beds24BookingService;
use App\Services\OwnerAlertService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class HousekeepingBotController extends Controller
{
    protected string $botToken;
    protected int    $mgmtGroupId;
    protected OwnerAlertService $ownerAlert;
    protected Beds24BookingService $beds24;

    // Jahongir Hotel property ID
    protected const PROPERTY_ID = 41097;

    public function __construct(OwnerAlertService $ownerAlert, Beds24BookingService $beds24)
    {
        $this->botToken    = config('services.housekeeping_bot.token', '');
        $this->mgmtGroupId = (int) config('services.housekeeping_bot.mgmt_group_id', 0);
        $this->ownerAlert  = $ownerAlert;
        $this->beds24      = $beds24;
    }

    // ── WEBHOOK ENTRY ────────────────────────────────────────────

    public function handleWebhook(Request $request)
    {
        try {
            Log::debug('HousekeepingBot webhook', ['data' => $request->all()]);

            if ($cb = $request->input('callback_query')) {
                return $this->handleCallback($cb);
            }

            if ($message = $request->input('message')) {
                return $this->handleMessage($message);
            }

            return response('OK');
        } catch (\Throwable $e) {
            Log::error('HousekeepingBot unhandled error', [
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
        $photo   = $message['photo'] ?? null;
        $contact = $message['contact'] ?? null;

        if (!$chatId) return response('OK');

        // Auth: phone contact
        if ($contact) return $this->handleAuth($chatId, $contact);

        $session = TelegramPosSession::where('chat_id', $chatId)->first();

        // Not authenticated
        if (!$session || !$session->user_id) {
            $this->send($chatId, "Telefon raqamingizni yuboring.", $this->phoneKb());
            return response('OK');
        }

        $session->updateActivity();

        // Photo received
        if ($photo) {
            // If in lost & found flow, handle as L&F photo
            if ($session->state === 'hk_lf_photo') {
                return $this->handleLostFoundPhoto($chatId, $session, $photo);
            }
            return $this->handlePhoto($chatId, $session, $photo);
        }

        // State-based handling (issue reporting flow)
        if (in_array($session->state, ['hk_issue_room', 'hk_issue_desc', 'hk_issue_photo'])) {
            return $this->handleIssueFlow($chatId, $session, $text);
        }

        // State-based handling (lost & found flow)
        if (in_array($session->state, ['hk_lf_photo', 'hk_lf_room', 'hk_lf_desc'])) {
            return $this->handleLostFoundFlow($chatId, $session, $text);
        }

        // State-based handling (stock alert flow)
        if (in_array($session->state, ['hk_stock_room', 'hk_stock_item'])) {
            return $this->handleStockAlertFlow($chatId, $session, $text);
        }

        // State-based handling (rush room flow)
        if ($session->state === 'hk_rush_room') {
            return $this->handleRushFlow($chatId, $session, $text);
        }

        // Reply keyboard button texts
        $user = User::find($session->user_id);
        $isManager = $user && $user->hasAnyRole(['super_admin', 'admin', 'manager', 'owner']);

        // Commands & button handlers
        if ($text === '/start' || $text === '🏠 Bosh sahifa') return $this->showWelcome($chatId, $session);
        if ($text === '/status' || $text === '📊 Xonalar holati') return $this->showStatus($chatId);
        if ($text === '/issues' || $text === '🔴 Muammolar') return $this->showIssues($chatId);
        if ($text === '/help' || $text === '❓ Yordam') return $this->showHelp($chatId, $isManager);
        if ($text === '📸 Muammo yuborish') {
            $session->update(['state' => 'hk_issue_photo', 'data' => null]);
            $this->send($chatId, "📸 Muammo rasmini yuboring.", $this->mainKb($isManager));
            return response('OK');
        }
        // New feature buttons
        if ($text === '📦 Topilma') {
            $session->update(['state' => 'hk_lf_photo', 'data' => null]);
            $this->send($chatId, "📦 Topilgan narsa rasmini yuboring.", $this->mainKb($isManager));
            return response('OK');
        }
        if ($text === '📢 Kam narsa') {
            $session->update(['state' => 'hk_stock_room', 'data' => null]);
            $this->send($chatId, "Qaysi xonada kam narsa bor? (1-15)", $this->mainKb($isManager));
            return response('OK');
        }
        if ($text === '🔴 TEZKOR' && $isManager) {
            $session->update(['state' => 'hk_rush_room', 'data' => null]);
            $this->send($chatId, "Qaysi xona TEZKOR tozalanishi kerak? (1-15)\nMehmon kelish vaqtini ham yozing. Masalan: <code>7 14:00</code>", $this->mainKb($isManager));
            return response('OK');
        }
        if ($text === '📦 Topilmalar') return $this->showLostFound($chatId);

        if ($text === '/alldirty' || $text === '🟡 Hammasini iflos') return $this->markAllDirty($chatId, $session);
        if ($text === '🟡 Xonani iflos') {
            $session->update(['state' => 'hk_dirty_room', 'data' => null]);
            $this->send($chatId, "Qaysi xonani iflos deb belgilash kerak? (1-15)", $this->mainKb($isManager));
            return response('OK');
        }
        if ($text === '/logout' || $text === '🚪 Chiqish') {
            $session->update(['user_id' => null, 'state' => 'idle', 'data' => null]);
            $this->send($chatId, "Chiqildi. Qayta kirish uchun telefon raqamingizni yuboring.", $this->phoneKb());
            return response('OK');
        }

        // /dirty 7 or /iflos 7 — managers only
        if (preg_match('/^\/(dirty|iflos)\s+(\d+)$/i', $text, $m)) {
            return $this->markDirty($chatId, $session, (int) $m[2]);
        }

        // State: waiting for room number to mark dirty
        if ($session->state === 'hk_dirty_room') {
            $rooms = $this->extractRoomNumbers($text);
            if (!empty($rooms)) {
                $session->update(['state' => 'hk_main', 'data' => null]);
                return $this->markDirty($chatId, $session, $rooms[0]);
            }
            $this->send($chatId, "Xona raqamini kiriting (1-15):");
            return response('OK');
        }

        // State: waiting for issue photo
        if ($session->state === 'hk_issue_photo') {
            $this->send($chatId, "📸 Rasm yuboring yoki /cancel bosing.");
            return response('OK');
        }

        // Parse room numbers from message: "7", "3,5,11", "7 xona tayyor", "11 14"
        $rooms = $this->extractRoomNumbers($text);
        if (!empty($rooms)) {
            return $this->markRoomsClean($chatId, $session, $rooms);
        }

        // /cancel — reset state
        if ($text === '/cancel' || $text === '❌ Bekor qilish') {
            $session->update(['state' => 'hk_main', 'data' => null]);
            $this->send($chatId, "Bekor qilindi.", $this->mainKb($isManager));
            return response('OK');
        }

        // Unknown input — show hint with keyboard
        $this->send($chatId, "Xona raqamini yozing (masalan: <code>7</code> yoki <code>3,5,11</code>)\n📸 Rasm yuboring — muammo haqida", $this->mainKb($isManager));
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

        $session = TelegramPosSession::where('chat_id', $chatId)->first();
        if (!$session || !$session->user_id) return response('OK');

        $user = User::find($session->user_id);
        $isManager = $user && $user->hasAnyRole(['super_admin', 'admin', 'manager', 'owner']);

        return match(true) {
            $data === 'status' => $this->showStatus($chatId),
            $data === 'issues' => $this->showIssues($chatId),
            $data === 'help' => $this->showHelp($chatId, $isManager),
            str_starts_with($data, 'resolve_') => $this->resolveIssue($chatId, $session, (int) substr($data, 8)),
            str_starts_with($data, 'clean_') => $this->markRoomsClean($chatId, $session, [(int) substr($data, 6)]),
            str_starts_with($data, 'dirty_') => $this->markDirty($chatId, $session, (int) substr($data, 6)),
            default => response('OK'),
        };
    }

    // ── AUTH ─────────────────────────────────────────────────────

    protected function handleAuth(int $chatId, array $contact)
    {
        $phone = preg_replace('/[^0-9]/', '', $contact['phone_number'] ?? '');
        $user  = User::where('phone_number', 'LIKE', '%' . substr($phone, -9))->first();

        if (!$user) {
            $this->send($chatId, "Raqam topilmadi. Rahbariyatga murojaat qiling.");
            return response('OK');
        }

        TelegramPosSession::updateOrCreate(
            ['chat_id' => $chatId],
            ['user_id' => $user->id, 'state' => 'hk_main', 'data' => null]
        );

        $this->send($chatId, "✅ Xush kelibsiz, {$user->name}!");
        return $this->showWelcome($chatId, TelegramPosSession::where('chat_id', $chatId)->first());
    }

    // ── WELCOME ──────────────────────────────────────────────────

    protected function showWelcome(int $chatId, $session)
    {
        $session->update(['state' => 'hk_main', 'data' => null]);

        $user = User::find($session->user_id);
        $name = $user?->name ?? '';
        $isManager = $user && $user->hasAnyRole(['super_admin', 'admin', 'manager', 'owner']);

        $text = "🧹 <b>Jahongir Hotel — Tozalik Bot</b>\n\n"
            . "Salom, <b>{$name}</b>! 👋\n\n"
            . "📌 <b>Asosiy imkoniyatlar:</b>\n"
            . "• Xona raqamini yozing → toza deb belgilanadi\n"
            . "  Masalan: <code>7</code> yoki <code>3,5,11</code>\n"
            . "• 📸 Rasm yuboring → muammo haqida xabar\n"
            . "• 📊 Xonalar holati — barcha xonalar\n"
            . "• 🔴 Muammolar — ochiq muammolar ro'yxati";

        $this->send($chatId, $text, $this->mainKb($isManager));
        return response('OK');
    }

    // ── MARK ROOMS CLEAN ─────────────────────────────────────────

    protected function markRoomsClean(int $chatId, $session, array $rooms)
    {
        $user    = User::find($session->user_id);
        $allStatuses = $this->beds24->getRoomStatuses(self::PROPERTY_ID);
        $cleaned = [];

        foreach ($rooms as $num) {
            $room = $this->findRoom($allStatuses, $num);
            if (!$room) continue;

            $ok = $this->beds24->updateRoomStatus(
                self::PROPERTY_ID,
                $room['room_type_id'],
                $room['unit_id'],
                'clean'
            );

            if ($ok) {
                $cleaned[] = $num;
                Log::info('HousekeepingBot: room marked clean in Beds24', [
                    'room_number' => $num,
                    'user_id'     => $session->user_id,
                    'user_name'   => $user?->name,
                ]);
            }
        }

        if (empty($cleaned)) {
            $this->send($chatId, "Xona topilmadi yoki Beds24 xatolik. Qaytadan urinib ko'ring.");
            return response('OK');
        }

        $time = now()->format('H:i');
        $name = $user?->name ?? 'Noma\'lum';

        if (count($cleaned) === 1) {
            $text = "✅ {$cleaned[0]}-xona — Toza!\n👤 {$name} | 🕐 {$time}";
        } else {
            $nums = implode(', ', $cleaned);
            $text = "✅ {$nums}-xonalar — Toza!\n👤 {$name} | 🕐 {$time}";
        }

        $isManager = $user && $user->hasAnyRole(['super_admin', 'admin', 'manager', 'owner']);
        $this->send($chatId, $text, $this->mainKb($isManager));
        return response('OK');
    }

    // ── MARK SINGLE ROOM DIRTY (manager) ─────────────────────────

    protected function markDirty(int $chatId, $session, int $roomNum)
    {
        $user = User::find($session->user_id);

        if (!$user || !$user->hasAnyRole(['super_admin', 'admin', 'manager', 'owner'])) {
            $this->send($chatId, "Bu buyruq faqat rahbariyat uchun.");
            return response('OK');
        }

        $allStatuses = $this->beds24->getRoomStatuses(self::PROPERTY_ID);
        $room = $this->findRoom($allStatuses, $roomNum);

        if (!$room) {
            $this->send($chatId, "Xona topilmadi. Beds24 da tekshiring.");
            return response('OK');
        }

        $ok = $this->beds24->updateRoomStatus(
            self::PROPERTY_ID,
            $room['room_type_id'],
            $room['unit_id'],
            'dirty'
        );

        if (!$ok) {
            $this->send($chatId, "⚠️ Beds24 xatolik. Qaytadan urinib ko'ring.", $this->mainKb(true));
            return response('OK');
        }

        Log::info('HousekeepingBot: room marked dirty in Beds24', [
            'room_number' => $roomNum,
            'user_id'     => $session->user_id,
            'user_name'   => $user?->name,
        ]);

        $this->send($chatId, "🟡 {$roomNum}-xona — Iflos deb belgilandi.", $this->mainKb(true));
        return response('OK');
    }

    // ── MARK ALL DIRTY (manager) ──────────────────────────────────

    protected function markAllDirty(int $chatId, $session)
    {
        $user = User::find($session->user_id);

        if (!$user || !$user->hasAnyRole(['super_admin', 'admin', 'manager', 'owner'])) {
            $this->send($chatId, "Bu buyruq faqat rahbariyat uchun.");
            return response('OK');
        }

        $allStatuses = $this->beds24->getRoomStatuses(self::PROPERTY_ID);
        $nonDirty = array_filter($allStatuses, fn($r) => $r['status'] !== 'dirty' && $r['status'] !== 'repair');

        if (!empty($nonDirty)) {
            $ok = $this->beds24->updateRoomStatusBatch(self::PROPERTY_ID, $nonDirty, 'dirty');
            if (!$ok) {
                $this->send($chatId, "⚠️ Beds24 xatolik. Qaytadan urinib ko'ring.", $this->mainKb(true));
                return response('OK');
            }
        }

        $count = count($allStatuses);
        Log::info('HousekeepingBot: all rooms marked dirty in Beds24', [
            'user_id'   => $session->user_id,
            'user_name' => $user?->name,
            'count'     => $count,
        ]);

        $this->send($chatId, "🟡 Barcha {$count} ta xona — Iflos deb belgilandi.", $this->mainKb(true));
        return response('OK');
    }

    // ── PHOTO → ISSUE FLOW ────────────────────────────────────────

    protected function handlePhoto(int $chatId, $session, array $photo)
    {
        // Pick the largest photo (last in array)
        $fileId = end($photo)['file_id'] ?? null;
        if (!$fileId) return response('OK');

        // Store file_id in session, ask for room number
        $session->update([
            'state' => 'hk_issue_room',
            'data'  => ['photo_file_id' => $fileId],
        ]);

        $this->send($chatId, "📸 Rasm qabul qilindi.\n\nNechanchi xona? (1-15)");
        return response('OK');
    }

    protected function handleIssueFlow(int $chatId, $session, string $text)
    {
        $state = $session->state;
        $data  = $session->data ?? [];

        if ($state === 'hk_issue_room') {
            // Extract room number
            $rooms = $this->extractRoomNumbers($text);
            if (empty($rooms)) {
                $this->send($chatId, "Xona raqamini kiriting (1-15):");
                return response('OK');
            }

            $roomNum = $rooms[0];
            $data['room_number'] = $roomNum;
            $session->update(['state' => 'hk_issue_desc', 'data' => $data]);
            $this->send($chatId, "Muammo nima? (Qisqacha yozing yoki /skip yuboring)");
            return response('OK');
        }

        if ($state === 'hk_issue_desc') {
            $description = ($text === '/skip') ? null : $text;
            $data['description'] = $description;

            return $this->saveIssue($chatId, $session, $data);
        }

        return response('OK');
    }

    protected function saveIssue(int $chatId, $session, array $data)
    {
        $roomNum    = $data['room_number'] ?? null;
        $fileId     = $data['photo_file_id'] ?? null;
        $desc       = $data['description'] ?? null;
        $user       = User::find($session->user_id);

        if (!$roomNum || !$fileId) {
            $this->send($chatId, "Xatolik yuz berdi. Qaytadan rasm yuboring.");
            $session->update(['state' => 'hk_main', 'data' => null]);
            return response('OK');
        }

        // Download photo from Telegram and save locally
        $photoPath = $this->downloadTelegramPhoto($fileId);

        // Save issue record
        $issue = RoomIssue::create([
            'room_number'      => $roomNum,
            'reported_by'      => $session->user_id,
            'photo_path'       => $photoPath,
            'telegram_file_id' => $fileId,
            'description'      => $desc,
            'priority'         => 'medium',
            'status'           => 'open',
        ]);

        Log::info('HousekeepingBot: issue reported', [
            'issue_id'    => $issue->id,
            'room_number' => $roomNum,
            'user_id'     => $session->user_id,
            'user_name'   => $user?->name,
        ]);

        // Forward alert to management group
        $this->forwardIssueToManagement($issue, $fileId, $user);

        // Reset session
        $session->update(['state' => 'hk_main', 'data' => null]);

        $isManager = $user && $user->hasAnyRole(['super_admin', 'admin', 'manager', 'owner']);
        $this->send($chatId, "✅ Muammo saqlandi! Rahbariyatga xabar berildi.", $this->mainKb($isManager));
        return response('OK');
    }

    // ── STATUS OVERVIEW ──────────────────────────────────────────

    protected function showStatus(int $chatId)
    {
        $rooms = $this->beds24->getRoomStatuses(self::PROPERTY_ID);

        if (empty($rooms)) {
            $this->send($chatId, "⚠️ Beds24 bilan aloqa yo'q. Keyinroq urinib ko'ring.");
            return response('OK');
        }

        $cleanCount = count(array_filter($rooms, fn($r) => $r['status'] === 'clean'));
        $dirtyCount = count(array_filter($rooms, fn($r) => $r['status'] === 'dirty'));
        $repairCount = count(array_filter($rooms, fn($r) => $r['status'] === 'repair'));

        $lines = ["🏨 <b>Jahongir Hotel — Xonalar</b>\n"];

        foreach ($rooms as $room) {
            $emoji = match ($room['status']) {
                'clean'  => '✅',
                'dirty'  => '🟡',
                'repair' => '🔧',
                default  => '❓',
            };
            $statusUz = match ($room['status']) {
                'clean'  => 'Toza',
                'dirty'  => 'Iflos',
                'repair' => 'Ta\'mirda',
                default  => $room['status'],
            };
            $lines[] = "{$emoji} <b>{$room['room_number']}</b>-xona — {$statusUz}";
        }

        $lines[] = "\n✅ Toza: {$cleanCount} | 🟡 Iflos: {$dirtyCount}" . ($repairCount ? " | 🔧 Ta'mirda: {$repairCount}" : '');

        // Inline buttons for dirty rooms (quick clean)
        $dirtyRooms = array_filter($rooms, fn($r) => $r['status'] === 'dirty');
        $buttons = [];
        $row = [];
        foreach ($dirtyRooms as $room) {
            $row[] = ['text' => "✅ {$room['room_number']}", 'callback_data' => "clean_{$room['room_number']}"];
            if (count($row) >= 5) {
                $buttons[] = $row;
                $row = [];
            }
        }
        if (!empty($row)) $buttons[] = $row;

        $p = [
            'chat_id' => $chatId,
            'text' => implode("\n", $lines),
            'parse_mode' => 'HTML',
        ];

        if (!empty($buttons)) {
            array_unshift($buttons, [['text' => '⬆️ Toza deb belgilash:', 'callback_data' => 'noop']]);
            $p['reply_markup'] = json_encode(['inline_keyboard' => $buttons]);
        }

        Http::timeout(10)->post("https://api.telegram.org/bot{$this->botToken}/sendMessage", $p);
        return response('OK');
    }

    // ── OPEN ISSUES ──────────────────────────────────────────────

    protected function showIssues(int $chatId)
    {
        $openIssues = RoomIssue::where('status', 'open')
            ->orderBy('created_at', 'desc')
            ->get();

        $count = $openIssues->count();

        if ($count === 0) {
            $this->send($chatId, "✅ Ochiq muammolar yo'q.");
            return response('OK');
        }

        $lines = ["🔴 <b>Ochiq muammolar: {$count}</b>\n"];
        $buttons = [];

        foreach ($openIssues as $issue) {
            $age  = $issue->created_at->diffForHumans(now(), true);
            $desc = $issue->description ? mb_substr($issue->description, 0, 40) : 'Tavsif yo\'q';
            $reporter = $issue->reporter?->name ?? '';
            $lines[] = "📍 <b>{$issue->room_number}-xona:</b> {$desc}\n   👤 {$reporter} | ⏱ {$age}";
            $buttons[] = [['text' => "✅ Hal qilish: {$issue->room_number}-xona", 'callback_data' => "resolve_{$issue->id}"]];
        }

        $text = implode("\n\n", $lines);

        $p = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode(['inline_keyboard' => $buttons]),
        ];

        Http::timeout(10)->post("https://api.telegram.org/bot{$this->botToken}/sendMessage", $p);
        return response('OK');
    }

    // ── FORWARD ISSUE TO MANAGEMENT GROUP ────────────────────────

    protected function forwardIssueToManagement(RoomIssue $issue, string $fileId, ?User $reporter)
    {
        if (!$this->mgmtGroupId) return;

        $name = $reporter?->name ?? 'Noma\'lum';
        $desc = $issue->description ? "\n📝 {$issue->description}" : '';

        $caption = "🔴 <b>Muammo!</b>\n"
            . "📍 {$issue->room_number}-xona\n"
            . "👤 {$name}{$desc}";

        SendTelegramNotificationJob::dispatch($this->botToken, 'sendPhoto', [
            'chat_id'    => $this->mgmtGroupId,
            'photo'      => $fileId,
            'caption'    => $caption,
            'parse_mode' => 'HTML',
        ]);
    }

    // ── HELP ───────────────────────────────────────────────────

    protected function showHelp(int $chatId, bool $isManager = false)
    {
        $text = "❓ <b>Yordam — Tozalik Bot</b>\n\n"

            . "📌 <b>Xonani toza deb belgilash:</b>\n"
            . "Xona raqamini yozing. Masalan:\n"
            . "• <code>7</code> — bitta xona\n"
            . "• <code>3,5,11</code> — bir nechta xona\n"
            . "• <code>7 tayyor</code> — ham ishlaydi\n\n"

            . "📸 <b>Muammo yuborish:</b>\n"
            . "1. 📸 Muammo yuborish tugmasini bosing\n"
            . "2. Rasm yuboring\n"
            . "3. Xona raqamini kiriting\n"
            . "4. Tavsif yozing (yoki /skip)\n"
            . "→ Rahbariyatga avtomatik xabar boradi\n\n"

            . "📊 <b>Xonalar holati:</b>\n"
            . "Toza va iflos xonalar ro'yxatini ko'rish\n\n"

            . "🔴 <b>Muammolar:</b>\n"
            . "Ochiq muammolar ro'yxati\n\n"

            . "📦 <b>Topilma:</b>\n"
            . "1. 📦 Topilma tugmasini bosing\n"
            . "2. Rasm yuboring\n"
            . "3. Xona raqamini kiriting\n"
            . "4. Nima topilganini yozing\n"
            . "→ Rahbariyatga xabar boradi\n\n"

            . "📢 <b>Kam narsa:</b>\n"
            . "1. 📢 Kam narsa tugmasini bosing\n"
            . "2. Xona raqamini kiriting\n"
            . "3. Nima kam ekanini yozing\n"
            . "→ Rahbariyatga xabar boradi";

        if ($isManager) {
            $text .= "\n\n"
                . "━━━━━━━━━━━━━━━━━━\n"
                . "👔 <b>Rahbariyat buyruqlari:</b>\n\n"
                . "🔴 <b>TEZKOR:</b>\n"
                . "Xonani tezkor tozalash uchun belgilash\n"
                . "Barcha tozalovchilarga xabar boradi\n\n"
                . "📦 <b>Topilmalar:</b>\n"
                . "Topilgan narsalar ro'yxati\n\n"
                . "🟡 <b>Xonani iflos:</b>\n"
                . "Bitta xonani iflos deb belgilash\n\n"
                . "🟡 <b>Hammasini iflos:</b>\n"
                . "Barcha 15 xonani iflos qilish\n\n"
                . "🔧 <b>Muammoni hal qilish:</b>\n"
                . "Muammolar ro'yxatida ✅ tugmasi";
        }

        $this->send($chatId, $text, $this->mainKb($isManager));
        return response('OK');
    }

    // ── RESOLVE ISSUE ──────────────────────────────────────────

    protected function resolveIssue(int $chatId, $session, int $issueId)
    {
        $user = User::find($session->user_id);

        $issue = RoomIssue::find($issueId);
        if (!$issue) {
            $this->send($chatId, "Muammo topilmadi.");
            return response('OK');
        }

        $issue->update([
            'status'      => 'resolved',
            'resolved_by' => $session->user_id,
            'resolved_at' => now(),
        ]);

        Log::info('HousekeepingBot: issue resolved', [
            'issue_id'    => $issueId,
            'room_number' => $issue->room_number,
            'user_name'   => $user?->name,
        ]);

        $this->send($chatId, "✅ Muammo hal qilindi! ({$issue->room_number}-xona)");
        return response('OK');
    }

    // ── LOST & FOUND FLOW ────────────────────────────────────────

    protected function handleLostFoundPhoto(int $chatId, $session, array $photo)
    {
        $fileId = end($photo)['file_id'] ?? null;
        if (!$fileId) return response('OK');

        $session->update([
            'state' => 'hk_lf_room',
            'data'  => ['photo_file_id' => $fileId],
        ]);

        $this->send($chatId, "📸 Rasm qabul qilindi.\n\nQaysi xonadan topildi? (1-15)");
        return response('OK');
    }

    protected function handleLostFoundFlow(int $chatId, $session, string $text)
    {
        if ($text === '/cancel' || $text === '❌ Bekor qilish') {
            $session->update(['state' => 'hk_main', 'data' => null]);
            $user = User::find($session->user_id);
            $isManager = $user && $user->hasAnyRole(['super_admin', 'admin', 'manager', 'owner']);
            $this->send($chatId, "Bekor qilindi.", $this->mainKb($isManager));
            return response('OK');
        }

        $state = $session->state;
        $data  = $session->data ?? [];

        if ($state === 'hk_lf_photo') {
            $this->send($chatId, "📸 Topilgan narsa rasmini yuboring.");
            return response('OK');
        }

        if ($state === 'hk_lf_room') {
            $rooms = $this->extractRoomNumbers($text);
            if (empty($rooms)) {
                $this->send($chatId, "Xona raqamini kiriting (1-15):");
                return response('OK');
            }
            $data['room_number'] = $rooms[0];
            $session->update(['state' => 'hk_lf_desc', 'data' => $data]);
            $this->send($chatId, "Narsa nima? Qisqacha yozing (masalan: <code>soat</code>, <code>telefon</code>)");
            return response('OK');
        }

        if ($state === 'hk_lf_desc') {
            $data['description'] = $text;
            return $this->saveLostFoundItem($chatId, $session, $data);
        }

        return response('OK');
    }

    protected function saveLostFoundItem(int $chatId, $session, array $data)
    {
        $user    = User::find($session->user_id);
        $fileId  = $data['photo_file_id'] ?? null;
        $roomNum = $data['room_number'] ?? null;
        $desc    = $data['description'] ?? '';

        $photoPath = $fileId ? $this->downloadTelegramPhoto($fileId, 'lost-found') : null;

        $item = LostFoundItem::create([
            'room_number'      => $roomNum,
            'found_by'         => $session->user_id,
            'photo_path'       => $photoPath,
            'telegram_file_id' => $fileId,
            'description'      => $desc,
            'status'           => 'found',
        ]);

        Log::info('HousekeepingBot: lost item recorded', [
            'item_id'     => $item->id,
            'room_number' => $roomNum,
            'description' => $desc,
            'user_name'   => $user?->name,
        ]);

        // Notify management group
        if ($this->mgmtGroupId && $fileId) {
            $name = $user?->name ?? 'Noma\'lum';
            $caption = "📦 <b>Topilma!</b>\n"
                . "📍 {$roomNum}-xona\n"
                . "📝 {$desc}\n"
                . "👤 {$name}";

            SendTelegramNotificationJob::dispatch($this->botToken, 'sendPhoto', [
                'chat_id'    => $this->mgmtGroupId,
                'photo'      => $fileId,
                'caption'    => $caption,
                'parse_mode' => 'HTML',
            ]);
        }

        $session->update(['state' => 'hk_main', 'data' => null]);

        $isManager = $user && $user->hasAnyRole(['super_admin', 'admin', 'manager', 'owner']);
        $this->send($chatId, "✅ Topilma saqlandi! #{$item->id}\n📍 {$roomNum}-xona | 📝 {$desc}", $this->mainKb($isManager));
        return response('OK');
    }

    protected function showLostFound(int $chatId)
    {
        $items = LostFoundItem::where('status', 'found')
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();

        if ($items->isEmpty()) {
            $this->send($chatId, "📦 Topilmalar yo'q.");
            return response('OK');
        }

        $lines = ["📦 <b>Topilmalar ro'yxati</b>\n"];

        foreach ($items as $item) {
            $age  = $item->created_at->diffForHumans(now(), true);
            $desc = mb_substr($item->description, 0, 40);
            $finder = $item->finder?->name ?? '';
            $lines[] = "📍 <b>{$item->room_number}-xona:</b> {$desc}\n   👤 {$finder} | ⏱ {$age}";
        }

        $this->send($chatId, implode("\n\n", $lines));
        return response('OK');
    }

    // ── STOCK ALERT FLOW ────────────────────────────────────────

    protected function handleStockAlertFlow(int $chatId, $session, string $text)
    {
        if ($text === '/cancel' || $text === '❌ Bekor qilish') {
            $session->update(['state' => 'hk_main', 'data' => null]);
            $user = User::find($session->user_id);
            $isManager = $user && $user->hasAnyRole(['super_admin', 'admin', 'manager', 'owner']);
            $this->send($chatId, "Bekor qilindi.", $this->mainKb($isManager));
            return response('OK');
        }

        $state = $session->state;
        $data  = $session->data ?? [];

        if ($state === 'hk_stock_room') {
            $rooms = $this->extractRoomNumbers($text);
            if (empty($rooms)) {
                $this->send($chatId, "Xona raqamini kiriting (1-15):");
                return response('OK');
            }
            $data['room_number'] = $rooms[0];
            $session->update(['state' => 'hk_stock_item', 'data' => $data]);
            $this->send($chatId, "Nima kam? (masalan: <code>sochiq</code>, <code>shampun</code>, <code>sovun</code>)");
            return response('OK');
        }

        if ($state === 'hk_stock_item') {
            $data['item'] = $text;
            $user = User::find($session->user_id);
            $name = $user?->name ?? 'Noma\'lum';
            $roomNum = $data['room_number'];

            Log::info('HousekeepingBot: stock alert', [
                'room_number' => $roomNum,
                'item'        => $text,
                'user_name'   => $name,
            ]);

            // Notify management group
            if ($this->mgmtGroupId) {
                $alert = "📢 <b>Kam narsa!</b>\n"
                    . "📍 {$roomNum}-xona\n"
                    . "🧴 {$text}\n"
                    . "👤 {$name}";

                SendTelegramNotificationJob::dispatch($this->botToken, 'sendMessage', [
                    'chat_id'    => $this->mgmtGroupId,
                    'text'       => $alert,
                    'parse_mode' => 'HTML',
                ]);
            }

            $session->update(['state' => 'hk_main', 'data' => null]);

            $isManager = $user && $user->hasAnyRole(['super_admin', 'admin', 'manager', 'owner']);
            $this->send($chatId, "✅ Xabar yuborildi!\n📍 {$roomNum}-xona | 🧴 {$text}", $this->mainKb($isManager));
            return response('OK');
        }

        return response('OK');
    }

    // ── RUSH ROOM FLOW ──────────────────────────────────────────

    protected function handleRushFlow(int $chatId, $session, string $text)
    {
        $user = User::find($session->user_id);

        // Parse room number and optional time: "7 14:00" or just "7"
        preg_match('/^(\d{1,2})(?:\s+(\d{1,2}:\d{2}))?/', trim($text), $m);

        if (empty($m[1])) {
            $this->send($chatId, "Xona raqamini kiriting (1-15). Masalan: <code>7 14:00</code>");
            return response('OK');
        }

        $roomNum = (int) $m[1];
        if ($roomNum < 1 || $roomNum > 15) {
            $this->send($chatId, "Xona 1-15 oralig'ida bo'lishi kerak.");
            return response('OK');
        }

        $time = $m[2] ?? null;
        $timeText = $time ? "⏰ Mehmon keladi: {$time}" : '';

        Log::info('HousekeepingBot: rush room flagged', [
            'room_number' => $roomNum,
            'arrival'     => $time,
            'user_name'   => $user?->name,
        ]);

        // Broadcast to all cleaners
        $this->notifyCleanersRush($roomNum, $user?->name ?? 'Rahbariyat', $timeText);

        $session->update(['state' => 'hk_main', 'data' => null]);

        $rushMsg = "🔴 TEZKOR tozalash buyrug'i yuborildi!\n📍 {$roomNum}-xona";
        if ($timeText) $rushMsg .= "\n{$timeText}";

        $this->send($chatId, $rushMsg, $this->mainKb(true));
        return response('OK');
    }

    protected function notifyCleanersRush(int $roomNum, string $managerName, string $timeText): void
    {
        $msg = "🔴 <b>TEZKOR TOZALASH!</b>\n\n"
            . "📍 <b>{$roomNum}-xona</b> tezkor tozalanishi kerak!\n"
            . "👔 Buyurdi: {$managerName}";

        if ($timeText) {
            $msg .= "\n{$timeText}";
        }

        $msg .= "\n\n🧹 Iltimos, imkon qadar tez tozalang!";

        $sessions = TelegramPosSession::whereNotNull('user_id')
            ->where('state', '!=', 'idle')
            ->get();

        foreach ($sessions as $s) {
            SendTelegramNotificationJob::dispatch($this->botToken, 'sendMessage', [
                'chat_id'    => $s->chat_id,
                'text'       => $msg,
                'parse_mode' => 'HTML',
            ]);
        }
    }

    // ── HELPERS ──────────────────────────────────────────────────

    /**
     * Find a room in Beds24 statuses by room number (unit name).
     */
    protected function findRoom(array $allStatuses, int $roomNum): ?array
    {
        foreach ($allStatuses as $room) {
            if ((int) $room['room_number'] === $roomNum) {
                return $room;
            }
        }
        return null;
    }

    /**
     * Extract valid room numbers (1-15) from any message text.
     * Handles: "7", "7 xona", "7-xona", "11,14", "11 14", "3, 5, 7 tayyor"
     */
    protected function extractRoomNumbers(string $text): array
    {
        // Find all numbers in the string
        preg_match_all('/\b(\d{1,2})\b/', $text, $matches);

        $rooms = [];
        foreach ($matches[1] as $num) {
            $n = (int) $num;
            if ($n >= 1 && $n <= 15) {
                $rooms[] = $n;
            }
        }

        return array_values(array_unique($rooms));
    }

    /**
     * Download a Telegram photo by file_id and save to local storage.
     * Returns the storage path or null on failure.
     */
    protected function downloadTelegramPhoto(string $fileId, string $folder = 'room-issues'): ?string
    {
        try {
            // Get file path from Telegram
            $resp = Http::timeout(10)->get(
                "https://api.telegram.org/bot{$this->botToken}/getFile",
                ['file_id' => $fileId]
            );

            if (!$resp->successful() || !$resp->json('ok')) {
                Log::warning('HousekeepingBot: getFile failed', ['file_id' => $fileId]);
                return null;
            }

            $filePath = $resp->json('result.file_path');
            if (!$filePath) return null;

            // Download the file content
            $fileResp = Http::timeout(30)->get(
                "https://api.telegram.org/file/bot{$this->botToken}/{$filePath}"
            );

            if (!$fileResp->successful()) {
                Log::warning('HousekeepingBot: file download failed', ['path' => $filePath]);
                return null;
            }

            // Save to storage
            $ext      = pathinfo($filePath, PATHINFO_EXTENSION) ?: 'jpg';
            $filename = $folder . '/' . date('Y/m') . '/' . uniqid('item_', true) . '.' . $ext;

            Storage::disk('public')->put($filename, $fileResp->body());

            return $filename;
        } catch (\Throwable $e) {
            Log::error('HousekeepingBot: downloadTelegramPhoto error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    protected function mainKb(bool $isManager = false): array
    {
        $rows = [
            [['text' => '📊 Xonalar holati']],
            [['text' => '📸 Muammo yuborish'], ['text' => '🔴 Muammolar']],
            [['text' => '📦 Topilma'], ['text' => '📢 Kam narsa']],
            [['text' => '❓ Yordam']],
        ];

        if ($isManager) {
            $rows[] = [['text' => '🔴 TEZKOR'], ['text' => '📦 Topilmalar']];
            $rows[] = [['text' => '🟡 Xonani iflos'], ['text' => '🟡 Hammasini iflos']];
        }

        $rows[] = [['text' => '🚪 Chiqish']];

        return [
            'keyboard'        => $rows,
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
                Log::warning('HousekeepingBot send failed', [
                    'chat'   => $chatId,
                    'status' => $resp->status(),
                    'body'   => $resp->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('HousekeepingBot send error', ['chat' => $chatId, 'error' => $e->getMessage()]);
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
            $msg  = "🔴 <b>Housekeeping Bot Error</b>\n\n"
                . "📍 {$context}\n"
                . "👤 {$user}\n"
                . "❌ " . mb_substr($e->getMessage(), 0, 200) . "\n"
                . "📄 " . basename($e->getFile()) . ":" . $e->getLine();
            $this->ownerAlert->sendShiftCloseReport($msg);
        } catch (\Throwable $ignore) {}
    }
}
