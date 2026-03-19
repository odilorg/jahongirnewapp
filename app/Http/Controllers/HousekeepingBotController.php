<?php

namespace App\Http\Controllers;

use App\Contracts\Telegram\BotResolverInterface;
use App\Contracts\Telegram\TelegramTransportInterface;
use App\Jobs\SendTelegramNotificationJob;
use Carbon\Carbon;
use App\Models\LostFoundItem;
use App\Models\RoomIssue;
use App\Models\RoomPriority;
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
    protected int    $mgmtGroupId;
    protected OwnerAlertService $ownerAlert;
    protected Beds24BookingService $beds24;
    protected BotResolverInterface $botResolver;
    protected TelegramTransportInterface $transport;

    // Jahongir Hotel property ID
    protected const PROPERTY_ID = 41097;

    public function __construct(
        OwnerAlertService $ownerAlert,
        Beds24BookingService $beds24,
        BotResolverInterface $botResolver,
        TelegramTransportInterface $transport,
    ) {
        $this->mgmtGroupId = (int) config('services.housekeeping_bot.mgmt_group_id', 0);
        $this->ownerAlert  = $ownerAlert;
        $this->beds24      = $beds24;
        $this->botResolver = $botResolver;
        $this->transport   = $transport;
    }

    // ── WEBHOOK ENTRY ────────────────────────────────────────────

    public function handleWebhook(Request $request)
    {
        $data = $request->all();
        $updateId = $data['update_id'] ?? null;

        $webhook = \App\Models\IncomingWebhook::create([
            'source'      => 'telegram:housekeeping',
            'event_id'    => $updateId ? "housekeeping:{$updateId}" : null,
            'payload'     => $data,
            'status'      => \App\Models\IncomingWebhook::STATUS_PENDING,
            'received_at' => now(),
        ]);

        \App\Jobs\ProcessTelegramUpdateJob::dispatch('housekeeping', $webhook->id);

        return response('OK');
    }

    public function processUpdate(array $data): void
    {
        try {
            if ($cb = $data['callback_query'] ?? null) { $this->handleCallback($cb); return; }
            if ($message = $data['message'] ?? null) { $this->handleMessage($message); return; }
        } catch (\Throwable $e) {
            Log::error('HousekeepingBot unhandled error', [
                'e'     => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->alertOwnerOnError('Webhook', $e);
            throw $e;
        }
    }

    // ── MESSAGE HANDLER ──────────────────────────────────────────

    protected function handleMessage(array $message)
    {
        $chatId  = $message['chat']['id'] ?? null;
        $text    = trim($message['text'] ?? '');
        $photo   = $message['photo'] ?? null;
        $voice   = $message['voice'] ?? null;
        $contact = $message['contact'] ?? null;

        if (!$chatId) return response('OK');

        // Skip auth flow for group chats — phone requests only work in private
        $chatType = $message['chat']['type'] ?? 'private';
        if ($chatType !== 'private') {
            return $this->handleGroupMessage($chatId, $message);
        }

        // Auth: phone contact
        if ($contact) return $this->handleAuth($chatId, $contact);

        $session = TelegramPosSession::where('chat_id', $chatId)->first();

        // Not authenticated
        if (!$session || !$session->user_id) {
            $this->send($chatId, "Telefon raqamingizni yuboring.", $this->phoneKb());
            return response('OK');
        }

        // Reload session fresh — prevents stale state from queue race conditions
        $session->refresh();
        $session->updateActivity();

        // TEMP DEBUG: trace message routing
        Log::debug('HK message routing', ['text' => $text, 'state' => $session->state, 'chat' => $chatId]);

        // ── GLOBAL COMMANDS: always win over subflow state ──────
        // Main menu buttons and slash commands escape any active flow.
        // This prevents users from getting trapped in abandoned wizards.
        $user = User::find($session->user_id);
        $isManager = $user && $user->hasAnyRole(['super_admin', 'admin', 'manager', 'owner']);

        $globalCommands = [
            '/start', '/today', '/status', '/issues', '/help', '/cancel', '/logout',
            '🏠 Bosh sahifa', '📅 Bugungi rejim', '📊 Xonalar holati',
            '🔴 Muammolar', '❓ Yordam',
        ];

        if (in_array($text, $globalCommands, true)) {
            // Reset stuck subflow state when user taps a main menu button
            $subflowStates = ['hk_issue_room', 'hk_issue_desc', 'hk_issue_photo',
                'hk_lf_photo', 'hk_lf_room', 'hk_lf_desc',
                'hk_stock_room', 'hk_stock_item', 'hk_rush_room',
                'hk_priority_room', 'hk_priority_level', 'hk_priority_reason', 'hk_priority_clear'];

            if (in_array($session->state, $subflowStates, true)) {
                $session->update(['state' => 'hk_main', 'data' => null]);
            }

            // Route to command handler
            if ($text === '/start' || $text === '🏠 Bosh sahifa') return $this->showWelcome($chatId, $session);
            if ($text === '/today' || $text === '📅 Bugungi rejim') return $this->showDailyPlan($chatId, now()->timezone('Asia/Tashkent')->format('Y-m-d'));
            if ($text === '/status' || $text === '📊 Xonalar holati') return $this->showStatus($chatId);
            if ($text === '/issues' || $text === '🔴 Muammolar') return $this->showIssues($chatId);
            if ($text === '/help' || $text === '❓ Yordam') return $this->showHelp($chatId, $isManager);
            if ($text === '/cancel') {
                $this->send($chatId, "Bekor qilindi.", $this->mainKb($isManager));
                return response('OK');
            }
        }

        // ── STATE TIMEOUT: auto-reset abandoned flows ──────────
        // If a subflow state is older than 2 hours, reset to main menu.
        $subflowStates = ['hk_issue_room', 'hk_issue_desc', 'hk_issue_photo',
            'hk_lf_photo', 'hk_lf_room', 'hk_lf_desc',
            'hk_stock_room', 'hk_stock_item', 'hk_rush_room',
            'hk_priority_room', 'hk_priority_level', 'hk_priority_reason'];

        if (in_array($session->state, $subflowStates, true)
            && $session->last_activity_at
            && Carbon::parse($session->last_activity_at)->addHours(2)->isPast()) {
            $session->update(['state' => 'hk_main', 'data' => null]);
            $this->send($chatId, "Avvalgi jarayon vaqt tugadi. Bosh sahifaga qaytdingiz.", $this->mainKb($isManager));
            return response('OK');
        }

        // ── MEDIA HANDLING ─────────────────────────────────────
        // Photo received
        if ($photo) {
            if ($session->state === 'hk_lf_photo') {
                return $this->handleLostFoundPhoto($chatId, $session, $photo);
            }
            return $this->handlePhoto($chatId, $session, $photo);
        }

        // Voice message received
        if ($voice) {
            return $this->handleVoice($chatId, $session, $voice);
        }

        if ($message['video_note'] ?? null) {
            return $this->handleVoice($chatId, $session, $message['video_note']);
        }

        // ── SUBFLOW STATE ROUTING ──────────────────────────────
        // Only reached if text is NOT a global command and state is NOT expired
        if (in_array($session->state, ['hk_issue_room', 'hk_issue_desc', 'hk_issue_photo'])) {
            return $this->handleIssueFlow($chatId, $session, $text);
        }

        if (in_array($session->state, ['hk_lf_photo', 'hk_lf_room', 'hk_lf_desc'])) {
            return $this->handleLostFoundFlow($chatId, $session, $text);
        }

        if (in_array($session->state, ['hk_stock_room', 'hk_stock_item'])) {
            return $this->handleStockAlertFlow($chatId, $session, $text);
        }

        if ($session->state === 'hk_rush_room') {
            return $this->handleRushFlow($chatId, $session, $text);
        }

        // Priority flow — manager only, re-checked on every step
        if (in_array($session->state, ['hk_priority_room', 'hk_priority_level', 'hk_priority_reason'])) {
            if (!$isManager) {
                $session->update(['state' => 'hk_main', 'data' => null]);
                $this->send($chatId, "Ruxsat yo'q.", $this->mainKb($isManager));
                return response('OK');
            }
            return $this->handlePriorityFlow($chatId, $session, $text, $user);
        }
        if ($session->state === 'hk_priority_clear') {
            if (!$isManager) {
                $session->update(['state' => 'hk_main', 'data' => null]);
                return response('OK');
            }
            return $this->handlePriorityClear($chatId, $session, $text);
        }

        // ── REMAINING MENU COMMANDS ────────────────────────────
        if ($text === '📸 Muammo yuborish') {
            $session->update(['state' => 'hk_issue_photo', 'data' => null]);
            $this->send($chatId, "📸 Rasm yoki 🎤 ovozli xabar yuboring.", $this->mainKb($isManager));
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
        // TEZKOR removed — replaced by ⭐ Ustuvorlik priority system
        // Priority management — manager only
        if ($text === '⭐ Ustuvorlik' && $isManager) {
            $session->update(['state' => 'hk_priority_room', 'data' => null]);
            $this->send($chatId, "⭐ Qaysi xona(lar)ga ustuvorlik berilsin?\n\nXona raqamlarini kiriting (masalan: <code>7</code> yoki <code>7, 12</code>)\n\n/cancel — bekor qilish", $this->mainKb($isManager));
            return response('OK');
        }
        if ($text === '❌ Ustuvorlik o\'chirish' && $isManager) {
            $session->update(['state' => 'hk_priority_clear', 'data' => null]);
            $this->send($chatId, "Qaysi xona(lar)dan ustuvorlikni olib tashlash kerak?\n\nXona raqamlarini kiriting:", $this->mainKb($isManager));
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
            $this->send($chatId, "📸 Rasm yoki 🎤 ovozli xabar yuboring.\n/cancel — bekor qilish");
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
        if (($cb['message']['chat']['type'] ?? 'private') !== 'private') return response('OK');
        $this->aCb($cbId);

        $session = TelegramPosSession::where('chat_id', $chatId)->first();
        if (!$session || !$session->user_id) return response('OK');

        $user = User::find($session->user_id);
        $isManager = $user && $user->hasAnyRole(['super_admin', 'admin', 'manager', 'owner']);

        return match(true) {
            $data === 'status' => $this->showStatus($chatId),
            $data === 'issues' => $this->showIssues($chatId),
            $data === 'help' => $this->showHelp($chatId, $isManager),
            str_starts_with($data, 'plan_') => $this->showDailyPlan($chatId, substr($data, 5)),
            str_starts_with($data, 'resolve_') => $this->resolveIssue($chatId, $session, (int) substr($data, 8)),
            str_starts_with($data, 'clean_') => $this->markRoomsClean($chatId, $session, [(int) substr($data, 6)]),
            str_starts_with($data, 'dirty_') => $this->markDirty($chatId, $session, (int) substr($data, 6)),
            // Priority level selection (inline buttons from priority flow)
            str_starts_with($data, 'priority_') => $this->handlePriorityLevelCallback($chatId, $session, substr($data, 9)),
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
            . "• 📅 Bugungi rejim — kim keladi, kim ketadi\n"
            . "• Xona raqamini yozing → toza deb belgilanadi\n"
            . "  Masalan: <code>7</code> yoki <code>3,5,11</code>\n"
            . "• 📸 Rasm yoki 🎤 ovozli xabar → muammo haqida\n"
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

        $time = now()->timezone('Asia/Tashkent')->format('H:i');
        $name = $user?->name ?? 'Noma\'lum';

        if (count($cleaned) === 1) {
            $text = "✅ {$cleaned[0]}-xona — Toza!\n👤 {$name} | 🕐 {$time}";
        } else {
            $nums = implode(', ', $cleaned);
            $text = "✅ {$nums}-xonalar — Toza!\n👤 {$name} | 🕐 {$time}";
        }

        // Notify management group
        if ($this->mgmtGroupId) {
            SendTelegramNotificationJob::dispatch('housekeeping', 'sendMessage', [
                'chat_id'    => $this->mgmtGroupId,
                'text'       => $text,
                'parse_mode' => 'HTML',
            ]);
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

    protected function handleVoice(int $chatId, $session, array $voice)
    {
        $fileId = $voice['file_id'] ?? null;
        if (!$fileId) return response('OK');

        $state = $session->state;
        $data  = $session->data ?? [];

        // ── Issue flow states ──

        // Voice during "waiting for photo" step — use as alternative to photo
        if ($state === 'hk_issue_photo') {
            $session->update([
                'state' => 'hk_issue_room',
                'data'  => ['voice_file_id' => $fileId],
            ]);
            $this->send($chatId, "🎤 Ovozli xabar qabul qilindi.\n\nNechanchi xona? (1-15)");
            return response('OK');
        }

        // Voice during "description" step — attach as voice description
        if ($state === 'hk_issue_desc') {
            $data['voice_file_id'] = $fileId;
            $data['description'] = $data['description'] ?? '🎤 Ovozli xabar';
            return $this->saveIssue($chatId, $session, $data);
        }

        // Voice during "room number" step — save voice, still need room
        if ($state === 'hk_issue_room') {
            $data['voice_file_id'] = $fileId;
            $session->update(['data' => $data]);
            $this->send($chatId, "🎤 Ovozli xabar qabul qilindi.\n\nNechanchi xona? (1-15)");
            return response('OK');
        }

        // ── Lost & Found flow states ──

        // Voice during L&F "waiting for photo" step — use as alternative
        if ($state === 'hk_lf_photo') {
            $session->update([
                'state' => 'hk_lf_room',
                'data'  => ['voice_file_id' => $fileId],
            ]);
            $this->send($chatId, "🎤 Ovozli xabar qabul qilindi.\n\nQaysi xonadan topildi? (1-15)");
            return response('OK');
        }

        // Voice during L&F "description" step — attach as voice description
        if ($state === 'hk_lf_desc') {
            $data['voice_file_id'] = $fileId;
            $data['description'] = $data['description'] ?? '🎤 Ovozli xabar';
            return $this->saveLostFoundItem($chatId, $session, $data);
        }

        // Voice during L&F "room number" step — save voice, still need room
        if ($state === 'hk_lf_room') {
            $data['voice_file_id'] = $fileId;
            $session->update(['data' => $data]);
            $this->send($chatId, "🎤 Qo'shimcha ovozli xabar saqlandi.\n\nQaysi xonadan topildi? (1-15)");
            return response('OK');
        }

        // ── Stock alert flow states ──

        // Voice during stock "item" step — use as item description
        if ($state === 'hk_stock_item') {
            $data['voice_file_id'] = $fileId;
            $data['item'] = '🎤 Ovozli xabar';
            $user = User::find($session->user_id);
            $name = $user?->name ?? 'Noma\'lum';
            $roomNum = $data['room_number'] ?? '?';

            Log::info('HousekeepingBot: stock alert with voice', [
                'room_number' => $roomNum,
                'user_name'   => $name,
            ]);

            if ($this->mgmtGroupId) {
                $caption = "📢 <b>Kam narsa!</b>\n"
                    . "📍 {$roomNum}-xona\n"
                    . "👤 {$name}";

                SendTelegramNotificationJob::dispatch('housekeeping', 'sendVoice', [
                    'chat_id'    => $this->mgmtGroupId,
                    'voice'      => $fileId,
                    'caption'    => $caption,
                    'parse_mode' => 'HTML',
                ]);
            }

            $session->update(['state' => 'hk_main', 'data' => null]);
            $isManager = $user && $user->hasAnyRole(['super_admin', 'admin', 'manager', 'owner']);
            $this->send($chatId, "✅ Xabar yuborildi!\n📍 {$roomNum}-xona | 🎤 Ovozli xabar", $this->mainKb($isManager));
            return response('OK');
        }

        // Voice during stock "room" step — save voice, still need room
        if ($state === 'hk_stock_room') {
            $data['voice_file_id'] = $fileId;
            $session->update(['data' => $data]);
            $this->send($chatId, "🎤 Ovozli xabar saqlandi.\n\nQaysi xonada kam narsa bor? (1-15)");
            return response('OK');
        }

        // Voice during other active flows — ignore voice, remind current step
        if (in_array($state, ['hk_rush_room', 'hk_dirty_room'])) {
            $this->send($chatId, "🎤 Ovozli xabar saqlanmadi.\n\nAvval xona raqamini kiriting (1-15):");
            return response('OK');
        }

        // Default: start new issue report with voice
        $session->update([
            'state' => 'hk_issue_room',
            'data'  => ['voice_file_id' => $fileId],
        ]);

        $this->send($chatId, "🎤 Ovozli xabar qabul qilindi.\n\nNechanchi xona? (1-15)");
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
            $this->send($chatId, "Muammo nima?\n\n✍️ Qisqacha yozing\n🎤 Ovozli xabar yuboring\n/skip — o'tkazish");
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
        $roomNum      = $data['room_number'] ?? null;
        $photoFileId  = $data['photo_file_id'] ?? null;
        $voiceFileId  = $data['voice_file_id'] ?? null;
        $desc         = $data['description'] ?? null;
        $user         = User::find($session->user_id);

        if (!$roomNum || (!$photoFileId && !$voiceFileId)) {
            $this->send($chatId, "Xatolik yuz berdi. Qaytadan rasm yoki ovozli xabar yuboring.");
            $session->update(['state' => 'hk_main', 'data' => null]);
            return response('OK');
        }

        // Download media from Telegram and save locally
        $photoPath = $photoFileId ? $this->downloadTelegramPhoto($photoFileId) : null;
        $voicePath = $voiceFileId ? $this->downloadTelegramVoice($voiceFileId) : null;

        // Save issue record
        $issue = RoomIssue::create([
            'room_number'      => $roomNum,
            'reported_by'      => $session->user_id,
            'photo_path'       => $photoPath,
            'voice_path'       => $voicePath,
            'telegram_file_id' => $photoFileId ?? $voiceFileId,
            'description'      => $desc,
            'priority'         => 'medium',
            'status'           => 'open',
        ]);

        Log::info('HousekeepingBot: issue reported', [
            'issue_id'    => $issue->id,
            'room_number' => $roomNum,
            'user_id'     => $session->user_id,
            'user_name'   => $user?->name,
            'has_photo'   => (bool) $photoFileId,
            'has_voice'   => (bool) $voiceFileId,
        ]);

        // Forward alert to management group
        $this->forwardIssueToManagement($issue, $photoFileId, $user, $voiceFileId);

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

        // Load today's priorities for badge display
        $priorities = RoomPriority::todayByRoom();

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
            $line = "{$emoji} <b>{$room['room_number']}</b>-xona — {$statusUz}";

            // Append priority badge if set for today
            $rn = (int) $room['room_number'];
            if (isset($priorities[$rn])) {
                $line .= " · {$priorities[$rn]->badge()} {$priorities[$rn]->label()}";
                if ($priorities[$rn]->reason) {
                    $short = mb_substr($priorities[$rn]->reason, 0, 40);
                    $line .= ": {$short}";
                }
            }

            $lines[] = $line;
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

        $kb = null;
        if (!empty($buttons)) {
            array_unshift($buttons, [['text' => '⬆️ Toza deb belgilash:', 'callback_data' => 'noop']]);
            $kb = ['inline_keyboard' => $buttons];
        }

        $this->send($chatId, implode("\n", $lines), $kb);
        return response('OK');
    }

    // ── DAILY PLAN (ARRIVALS / DEPARTURES / STAYOVERS) ──────────

    protected function showDailyPlan(int $chatId, string $date)
    {
        try {
            $dateObj = \Carbon\Carbon::parse($date)->startOfDay();
        } catch (\Throwable $e) {
            $dateObj = now()->timezone('Asia/Tashkent')->startOfDay();
        }

        $dateStr = $dateObj->format('Y-m-d');
        $today = now()->timezone('Asia/Tashkent')->startOfDay();
        $isToday = $dateObj->eq($today);
        $isTomorrow = $dateObj->eq($today->copy()->addDay());

        $dayLabel = $isToday ? 'Bugun' : ($isTomorrow ? 'Ertaga' : $dateObj->format('d.m (D)'));

        // Build room map: roomTypeId + unitId → room number
        $roomStatuses = $this->beds24->getRoomStatuses(self::PROPERTY_ID);
        $roomMap = [];
        foreach ($roomStatuses as $r) {
            $key = $r['room_type_id'] . '_' . $r['unit_id'];
            $roomMap[$key] = $r['room_number'];
        }

        // Fetch arrivals for this date
        $arrivalsResp = $this->beds24->getBookings([
            'arrival' => $dateStr,
            'propertyId' => [(string) self::PROPERTY_ID],
        ]);
        $arrivals = $arrivalsResp['data'] ?? [];

        // Fetch departures for this date
        $departuresResp = $this->beds24->getBookings([
            'departure' => $dateStr,
            'propertyId' => [(string) self::PROPERTY_ID],
        ]);
        $departures = $departuresResp['data'] ?? [];

        // Fetch current bookings to find stayovers
        // Stayovers = arrived before this date AND departing after this date
        $currentResp = $this->beds24->getBookings([
            'filter' => 'current',
            'propertyId' => [(string) self::PROPERTY_ID],
        ]);
        $currentAll = $currentResp['data'] ?? [];

        // Filter: stayovers are guests who arrived before $date and depart after $date
        $stayovers = array_filter($currentAll, function ($b) use ($dateStr) {
            return $b['arrival'] < $dateStr && $b['departure'] > $dateStr
                && !in_array($b['status'], ['cancelled', 'declined']);
        });

        // Filter cancelled bookings and deduplicate by booking ID
        $dedup = fn(array $list) => array_values(collect($list)
            ->filter(fn($b) => !in_array($b['status'], ['cancelled', 'declined']))
            ->unique('id')
            ->all());

        $arrivals = $dedup($arrivals);
        $departures = $dedup($departures);
        $stayovers = array_values($stayovers); // re-index

        // Deduplicate stayovers by booking ID too
        $seenIds = [];
        $stayovers = array_filter($stayovers, function ($b) use (&$seenIds) {
            if (in_array($b['id'], $seenIds)) return false;
            $seenIds[] = $b['id'];
            return true;
        });

        // Load active priorities for this date, keyed by room number
        $priorities = RoomPriority::where('priority_date', $dateStr)->get()->keyBy('room_number')->all();

        // Helper: format a booking line with priority badge
        $formatRoom = function (array $b, string $icon) use ($roomMap, $priorities) {
            $roomKey = $b['roomId'] . '_' . $b['unitId'];
            $roomNum = $roomMap[$roomKey] ?? '?';
            $guests = ($b['numAdult'] ?? 0) + ($b['numChild'] ?? 0);
            $line = "  {$icon} <b>{$roomNum}</b>-xona · {$guests} kishi";

            // Append priority badge + reason if set for this room
            $numRoom = is_numeric($roomNum) ? (int) $roomNum : null;
            if ($numRoom && isset($priorities[$numRoom])) {
                $line .= ' · ' . $priorities[$numRoom]->formatForCleaner();
            }

            return ['line' => $line, 'roomNum' => $numRoom];
        };

        // Helper: sort by priority (urgent first, then important, then normal)
        $sortByPriority = function (array $items) use ($priorities) {
            usort($items, function ($a, $b) use ($priorities) {
                $wA = isset($priorities[$a['roomNum']]) ? $priorities[$a['roomNum']]->sortWeight() : 2;
                $wB = isset($priorities[$b['roomNum']]) ? $priorities[$b['roomNum']]->sortWeight() : 2;
                return $wA <=> $wB ?: ($a['roomNum'] ?? 0) <=> ($b['roomNum'] ?? 0);
            });
            return $items;
        };

        // Build the message
        $lines = ["📅 <b>{$dayLabel} — Tozalash rejimi</b>"];
        $lines[] = "📆 {$dateObj->format('d.m.Y')}";
        $lines[] = '';

        // Departures = rooms to deep-clean for turnover (sorted by priority)
        if (!empty($departures)) {
            $lines[] = "🔴 <b>Ketayotgan (" . count($departures) . "):</b>";
            $lines[] = "<i>Chuqur tozalash kerak</i>";
            $items = array_map(fn ($b) => $formatRoom($b, '🚪'), $departures);
            foreach ($sortByPriority($items) as $item) {
                $lines[] = $item['line'];
            }
            $lines[] = '';
        }

        // Arrivals = rooms to prepare for new guests (sorted by priority)
        if (!empty($arrivals)) {
            $lines[] = "🟢 <b>Kelayotgan (" . count($arrivals) . "):</b>";
            $lines[] = "<i>Yangi mehmon uchun tayyorlash</i>";
            $items = [];
            foreach ($arrivals as $b) {
                $roomKey = $b['roomId'] . '_' . $b['unitId'];
                $roomNum = $roomMap[$roomKey] ?? '?';
                $guests = ($b['numAdult'] ?? 0) + ($b['numChild'] ?? 0);
                $nights = '';
                try {
                    $nights = Carbon::parse($b['arrival'])->diffInDays(Carbon::parse($b['departure']));
                    $nights = " · {$nights} kecha";
                } catch (\Throwable $e) {}
                $arrival = $b['arrivalTime'] ? " · ⏰ {$b['arrivalTime']}" : '';
                $line = "  🛬 <b>{$roomNum}</b>-xona · {$guests} kishi{$nights}{$arrival}";

                $numRoom = is_numeric($roomNum) ? (int) $roomNum : null;
                if ($numRoom && isset($priorities[$numRoom])) {
                    $line .= ' · ' . $priorities[$numRoom]->formatForCleaner();
                }
                $items[] = ['line' => $line, 'roomNum' => $numRoom];
            }
            foreach ($sortByPriority($items) as $item) {
                $lines[] = $item['line'];
            }
            $lines[] = '';
        }

        // Stayovers = rooms for light cleaning
        if (!empty($stayovers)) {
            $lines[] = "🔵 <b>Qolayotgan (" . count($stayovers) . "):</b>";
            $lines[] = "<i>Yengil tozalash (sochiq, axlat)</i>";
            $items = [];
            foreach ($stayovers as $b) {
                $roomKey = $b['roomId'] . '_' . $b['unitId'];
                $roomNum = $roomMap[$roomKey] ?? '?';
                $guests = ($b['numAdult'] ?? 0) + ($b['numChild'] ?? 0);
                $depDate = Carbon::parse($b['departure'])->format('d.m');
                $line = "  🛏 <b>{$roomNum}</b>-xona · {$guests} kishi (→{$depDate})";

                $numRoom = is_numeric($roomNum) ? (int) $roomNum : null;
                if ($numRoom && isset($priorities[$numRoom])) {
                    $line .= ' · ' . $priorities[$numRoom]->formatForCleaner();
                }
                $items[] = ['line' => $line, 'roomNum' => $numRoom];
            }
            foreach ($sortByPriority($items) as $item) {
                $lines[] = $item['line'];
            }
            $lines[] = '';
        }

        if (empty($arrivals) && empty($departures) && empty($stayovers)) {
            $lines[] = "✨ Bugun mehmonlar yo'q.";
            $lines[] = '';
        }

        // Summary
        $totalClean = count($departures) + count($arrivals) + count($stayovers);
        $lines[] = "━━━━━━━━━━━━━━━━━━";
        $lines[] = "🧹 Jami tozalash: <b>{$totalClean}</b> xona";
        if (!empty($departures)) $lines[] = "  🔴 Chuqur: " . count($departures);
        if (!empty($arrivals)) $lines[] = "  🟢 Yangi mehmon: " . count($arrivals);
        if (!empty($stayovers)) $lines[] = "  🔵 Yengil: " . count($stayovers);

        $text = implode("\n", $lines);

        // Navigation buttons
        $prevDate = $dateObj->copy()->subDay()->format('Y-m-d');
        $nextDate = $dateObj->copy()->addDay()->format('Y-m-d');
        $buttons = [
            [
                ['text' => '⬅️ Oldingi kun', 'callback_data' => "plan_{$prevDate}"],
                ['text' => 'Keyingi kun ➡️', 'callback_data' => "plan_{$nextDate}"],
            ],
        ];
        if (!$isToday) {
            $todayDate = $today->format('Y-m-d');
            $buttons[] = [['text' => '📅 Bugun', 'callback_data' => "plan_{$todayDate}"]];
        }

        $this->send($chatId, $text, ['inline_keyboard' => $buttons]);
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

        $this->send($chatId, $text, ['inline_keyboard' => $buttons]);
        return response('OK');
    }

    // ── FORWARD ISSUE TO MANAGEMENT GROUP ────────────────────────

    protected function forwardIssueToManagement(RoomIssue $issue, ?string $photoFileId, ?User $reporter, ?string $voiceFileId = null)
    {
        if (!$this->mgmtGroupId) return;

        $name = $reporter?->name ?? 'Noma\'lum';
        $desc = $issue->description ? "\n📝 {$issue->description}" : '';

        $caption = "🔴 <b>Muammo!</b>\n"
            . "📍 {$issue->room_number}-xona\n"
            . "👤 {$name}{$desc}";

        if ($photoFileId) {
            SendTelegramNotificationJob::dispatch('housekeeping', 'sendPhoto', [
                'chat_id'    => $this->mgmtGroupId,
                'photo'      => $photoFileId,
                'caption'    => $caption,
                'parse_mode' => 'HTML',
            ]);
        }

        if ($voiceFileId) {
            // If no photo, include the caption with the voice message
            // If photo was already sent, just send voice as follow-up
            $voiceCaption = $photoFileId
                ? "🎤 Ovozli xabar — {$issue->room_number}-xona"
                : $caption;

            SendTelegramNotificationJob::dispatch('housekeeping', 'sendVoice', [
                'chat_id'    => $this->mgmtGroupId,
                'voice'      => $voiceFileId,
                'caption'    => $voiceCaption,
                'parse_mode' => 'HTML',
            ]);
        }

        // If neither photo nor voice, send text-only alert
        if (!$photoFileId && !$voiceFileId) {
            SendTelegramNotificationJob::dispatch('housekeeping', 'sendMessage', [
                'chat_id'    => $this->mgmtGroupId,
                'text'       => $caption,
                'parse_mode' => 'HTML',
            ]);
        }
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
        $user         = User::find($session->user_id);
        $photoFileId  = $data['photo_file_id'] ?? null;
        $voiceFileId  = $data['voice_file_id'] ?? null;
        $roomNum      = $data['room_number'] ?? null;
        $desc         = $data['description'] ?? '';

        $photoPath = $photoFileId ? $this->downloadTelegramPhoto($photoFileId, 'lost-found') : null;
        $voicePath = $voiceFileId ? $this->downloadTelegramVoice($voiceFileId) : null;

        $item = LostFoundItem::create([
            'room_number'      => $roomNum,
            'found_by'         => $session->user_id,
            'photo_path'       => $photoPath,
            'telegram_file_id' => $photoFileId ?? $voiceFileId,
            'description'      => $desc,
            'status'           => 'found',
        ]);

        Log::info('HousekeepingBot: lost item recorded', [
            'item_id'     => $item->id,
            'room_number' => $roomNum,
            'description' => $desc,
            'user_name'   => $user?->name,
            'has_photo'   => (bool) $photoFileId,
            'has_voice'   => (bool) $voiceFileId,
        ]);

        // Notify management group
        if ($this->mgmtGroupId) {
            $name = $user?->name ?? 'Noma\'lum';
            $caption = "📦 <b>Topilma!</b>\n"
                . "📍 {$roomNum}-xona\n"
                . "📝 {$desc}\n"
                . "👤 {$name}";

            if ($photoFileId) {
                SendTelegramNotificationJob::dispatch('housekeeping', 'sendPhoto', [
                    'chat_id'    => $this->mgmtGroupId,
                    'photo'      => $photoFileId,
                    'caption'    => $caption,
                    'parse_mode' => 'HTML',
                ]);
            }

            if ($voiceFileId) {
                $voiceCaption = $photoFileId
                    ? "🎤 Ovozli xabar — {$roomNum}-xona topilma"
                    : $caption;
                SendTelegramNotificationJob::dispatch('housekeeping', 'sendVoice', [
                    'chat_id'    => $this->mgmtGroupId,
                    'voice'      => $voiceFileId,
                    'caption'    => $voiceCaption,
                    'parse_mode' => 'HTML',
                ]);
            }

            if (!$photoFileId && !$voiceFileId) {
                SendTelegramNotificationJob::dispatch('housekeeping', 'sendMessage', [
                    'chat_id'    => $this->mgmtGroupId,
                    'text'       => $caption,
                    'parse_mode' => 'HTML',
                ]);
            }
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
            $this->send($chatId, "Nima kam?\n✍️ Yozing (masalan: <code>sochiq</code>, <code>shampun</code>)\n🎤 Yoki ovozli xabar yuboring");
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

                SendTelegramNotificationJob::dispatch('housekeeping', 'sendMessage', [
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

        // Only send to housekeeping sessions (positive chat_id, hk_ state prefix)
        // Avoids cross-bot broadcast to cashier/kitchen sessions sharing the same table
        $sessions = TelegramPosSession::whereNotNull('user_id')
            ->where('chat_id', '>', 0)
            ->where(function ($q) {
                $q->where('state', 'LIKE', 'hk_%')
                  ->orWhere('state', 'main_menu');
            })
            ->get();

        foreach ($sessions as $s) {
            SendTelegramNotificationJob::dispatch('housekeeping', 'sendMessage', [
                'chat_id'    => $s->chat_id,
                'text'       => $msg,
                'parse_mode' => 'HTML',
            ]);
        }
    }

    // ── ROOM PRIORITY FLOW ─────────────────────────────────────

    /**
     * Inline button callback: admin selected priority level (urgent/important).
     * Advances flow to reason step.
     */
    protected function handlePriorityLevelCallback(int $chatId, $session, string $level)
    {
        // Reload session fresh — prevents race conditions from double-clicks
        $session->refresh();

        // Only proceed if still waiting for level selection
        if ($session->state !== 'hk_priority_level') {
            Log::debug('HK priority callback: state already advanced, ignoring duplicate', ['state' => $session->state]);
            return response('OK');
        }

        $user = User::find($session->user_id);
        if (!$user || !$user->hasAnyRole(['super_admin', 'admin', 'manager', 'owner'])) {
            return response('OK');
        }

        if (!in_array($level, ['urgent', 'important'], true)) return response('OK');

        $data = $session->data ?? [];
        $data['priority'] = $level;

        $session->update([
            'state' => 'hk_priority_reason',
            'data' => $data,
        ]);

        $badge = $level === 'urgent' ? '🔴 Shoshilinch' : '🟡 Muhim';
        $this->send($chatId, "{$badge} tanlandi.\n\nSababni yozing (masalan: <i>VIP mehmon 14:00 da</i>)\n\n/skip — sababsiz saqlash\n/cancel — bekor qilish");
        return response('OK');
    }

    /**
     * Multi-step priority assignment: room(s) → level → optional reason.
     * Manager auth re-checked on every step (see routing above).
     */
    protected function handlePriorityFlow(int $chatId, $session, string $text, ?User $user)
    {
        $state = $session->state;
        $data = $session->data ?? [];

        // Step 2 (waiting for inline button): remind user to tap the button
        if ($state === 'hk_priority_level') {
            $this->send($chatId, "⬆️ Yuqoridagi tugmalardan birini bosing:\n🔴 Shoshilinch yoki 🟡 Muhim\n\n/cancel — bekor qilish");
            return response('OK');
        }

        // Step 1: Collect room numbers
        if ($state === 'hk_priority_room') {
            $rooms = $this->extractRoomNumbers($text);
            if (empty($rooms)) {
                $this->send($chatId, "Xona raqamini to'g'ri kiriting (1-15).\n/cancel — bekor qilish");
                return response('OK');
            }

            $session->update([
                'state' => 'hk_priority_level',
                'data' => ['rooms' => $rooms],
            ]);

            $roomList = implode(', ', array_map(fn ($r) => "{$r}-xona", $rooms));
            $this->send($chatId, "Xonalar: <b>{$roomList}</b>\n\nUstuvorlik darajasini tanlang:", [
                'inline_keyboard' => [
                    [
                        ['text' => '🔴 Shoshilinch', 'callback_data' => 'priority_urgent'],
                        ['text' => '🟡 Muhim', 'callback_data' => 'priority_important'],
                    ],
                ],
            ]);
            return response('OK');
        }

        // Step 3: Collect reason (after level is chosen via callback)
        if ($state === 'hk_priority_reason') {
            $reason = trim($text);
            if ($reason === '' || $reason === '/skip') {
                $reason = null;
            }

            $rooms = $data['rooms'] ?? [];
            $priority = $data['priority'] ?? 'important';

            // Save priorities — idempotent via updateOrCreate
            foreach ($rooms as $roomNum) {
                RoomPriority::setPriority(
                    roomNumber: $roomNum,
                    priority: $priority,
                    reason: $reason,
                    setBy: $user?->id,
                );
            }

            $session->update(['state' => 'hk_main', 'data' => null]);

            $badge = $priority === 'urgent' ? '🔴' : '🟡';
            $label = $priority === 'urgent' ? 'SHOSHILINCH' : 'MUHIM';
            $roomList = implode(', ', array_map(fn ($r) => "{$r}-xona", $rooms));
            $reasonText = $reason ? "\n📝 Sabab: {$reason}" : '';

            $this->send($chatId, "✅ Ustuvorlik belgilandi!\n\n{$badge} {$label}: {$roomList}{$reasonText}", $this->mainKb(true));

            // Notify management group
            $this->notifyPrioritySet($rooms, $priority, $reason, $user?->name ?? '?');

            return response('OK');
        }

        // Unexpected state — reset
        $session->update(['state' => 'hk_main', 'data' => null]);
        return response('OK');
    }

    /**
     * Handle priority clear flow (entered via '❌ Ustuvorlik o'chirish' button).
     */
    protected function handlePriorityClear(int $chatId, $session, string $text)
    {
        $rooms = $this->extractRoomNumbers($text);
        if (empty($rooms)) {
            $this->send($chatId, "Xona raqamini to'g'ri kiriting (1-15).\n/cancel — bekor qilish");
            return response('OK');
        }

        foreach ($rooms as $roomNum) {
            RoomPriority::clearPriority($roomNum);
        }

        $session->update(['state' => 'hk_main', 'data' => null]);

        $roomList = implode(', ', array_map(fn ($r) => "{$r}-xona", $rooms));
        $user = User::find($session->user_id);
        $this->send($chatId, "✅ Ustuvorlik olib tashlandi: {$roomList}", $this->mainKb(true));

        // Notify management group
        if ($this->mgmtGroupId) {
            SendTelegramNotificationJob::dispatch('housekeeping', 'sendMessage', [
                'chat_id' => $this->mgmtGroupId,
                'text' => "⚪ Ustuvorlik olib tashlandi: {$roomList}\n👔 {$user?->name}",
                'parse_mode' => 'HTML',
            ]);
        }

        return response('OK');
    }

    /**
     * Send priority notification to management group.
     * Non-fatal: notification failure does not roll back saved priority.
     */
    protected function notifyPrioritySet(array $rooms, string $priority, ?string $reason, string $managerName): void
    {
        if (!$this->mgmtGroupId) return;

        $badge = $priority === 'urgent' ? '🔴' : '🟡';
        $label = $priority === 'urgent' ? 'SHOSHILINCH' : 'MUHIM';
        $roomList = implode(', ', array_map(fn ($r) => "{$r}-xona", $rooms));
        $reasonLine = $reason ? "\n📝 {$reason}" : '';

        $msg = "⭐ <b>Ustuvorlik belgilandi</b>\n\n"
            . "{$badge} <b>{$label}</b>: {$roomList}{$reasonLine}\n"
            . "👔 {$managerName}\n"
            . "⏰ " . now('Asia/Tashkent')->format('H:i');

        try {
            SendTelegramNotificationJob::dispatch('housekeeping', 'sendMessage', [
                'chat_id' => $this->mgmtGroupId,
                'text' => $msg,
                'parse_mode' => 'HTML',
            ]);
        } catch (\Throwable $e) {
            Log::warning('HousekeepingBot: priority notification failed', ['error' => $e->getMessage()]);
        }
    }

    // ── GROUP MESSAGES ────────────────────────────────────────────

    /**
     * Handle messages from group chats. Group chats cannot request phone numbers,
     * so we skip auth and just return OK for now.
     */
    protected function handleGroupMessage(int $chatId, array $message)
    {
        // Group messages are informational only — no bot interaction needed
        return response('OK');
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
            $bot = $this->botResolver->resolve('housekeeping');

            // Get file path from Telegram via transport
            $result = $this->transport->call($bot, 'getFile', ['file_id' => $fileId]);

            if (!$result->succeeded()) {
                Log::warning('HousekeepingBot: getFile failed', ['file_id' => $fileId]);
                return null;
            }

            $filePath = $result->result['file_path'] ?? null;
            if (!$filePath) return null;

            // Download the file content (raw file download — not a Bot API method)
            $fileResp = Http::timeout(30)->get(
                "https://api.telegram.org/file/bot{$bot->token}/{$filePath}"
            );

            if (!$fileResp->successful()) {
                Log::warning('HousekeepingBot: file download failed');
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

    protected function downloadTelegramVoice(string $fileId): ?string
    {
        try {
            $bot = $this->botResolver->resolve('housekeeping');

            $result = $this->transport->call($bot, 'getFile', ['file_id' => $fileId]);

            if (!$result->succeeded()) {
                Log::warning('HousekeepingBot: getFile failed for voice', ['file_id' => $fileId]);
                return null;
            }

            $filePath = $result->result['file_path'] ?? null;
            if (!$filePath) return null;

            // Raw file download — not a Bot API method
            $fileResp = Http::timeout(30)->get(
                "https://api.telegram.org/file/bot{$bot->token}/{$filePath}"
            );

            if (!$fileResp->successful()) {
                Log::warning('HousekeepingBot: voice download failed');
                return null;
            }

            $ext      = pathinfo($filePath, PATHINFO_EXTENSION) ?: 'ogg';
            $filename = 'room-issues/' . date('Y/m') . '/' . uniqid('voice_', true) . '.' . $ext;

            Storage::disk('public')->put($filename, $fileResp->body());

            return $filename;
        } catch (\Throwable $e) {
            Log::error('HousekeepingBot: downloadTelegramVoice error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    protected function mainKb(bool $isManager = false): array
    {
        $rows = [
            [['text' => '📅 Bugungi rejim'], ['text' => '📊 Xonalar holati']],
            [['text' => '📸 Muammo yuborish'], ['text' => '🔴 Muammolar']],
            [['text' => '📦 Topilma'], ['text' => '📢 Kam narsa']],
            [['text' => '❓ Yordam']],
        ];

        if ($isManager) {
            $rows[] = [['text' => '⭐ Ustuvorlik'], ['text' => '❌ Ustuvorlik o\'chirish']];
            $rows[] = [['text' => '📦 Topilmalar'], ['text' => '🟡 Xonani iflos']];
            $rows[] = [['text' => '🟡 Hammasini iflos']];
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
        $extra = ['parse_mode' => 'HTML'];
        if ($kb) $extra['reply_markup'] = json_encode($kb);

        try {
            $bot = $this->botResolver->resolve('housekeeping');
            $result = $this->transport->sendMessage($bot, $chatId, $text, $extra);
            if (!$result->succeeded()) {
                Log::warning('HousekeepingBot send failed', ['chat' => $chatId, 'status' => $result->httpStatus]);
            }
        } catch (\Throwable $e) {
            Log::error('HousekeepingBot send error', ['chat' => $chatId, 'error' => $e->getMessage()]);
        }
    }

    protected function aCb(string $id): void
    {
        if (!$id) return;
        try {
            $bot = $this->botResolver->resolve('housekeeping');
            $this->transport->call($bot, 'answerCallbackQuery', ['callback_query_id' => $id]);
        } catch (\Throwable $e) {
            Log::warning('HousekeepingBot aCb failed', ['id' => $id, 'error' => $e->getMessage()]);
        }
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
