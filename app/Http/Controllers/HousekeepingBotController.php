<?php

namespace App\Http\Controllers;

use App\Models\RoomStatus;
use App\Models\RoomCleaning;
use App\Models\RoomIssue;
use App\Models\TelegramPosSession;
use App\Models\User;
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

    public function __construct(OwnerAlertService $ownerAlert)
    {
        $this->botToken    = config('services.housekeeping_bot.token', '');
        $this->mgmtGroupId = (int) config('services.housekeeping_bot.mgmt_group_id', 0);
        $this->ownerAlert  = $ownerAlert;
    }

    // ── WEBHOOK ENTRY ────────────────────────────────────────────

    public function handleWebhook(Request $request)
    {
        try {
            Log::debug('HousekeepingBot webhook', ['data' => $request->all()]);

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

        // Photo received — start issue flow
        if ($photo) {
            return $this->handlePhoto($chatId, $session, $photo);
        }

        // State-based handling (issue reporting flow)
        if (in_array($session->state, ['hk_issue_room', 'hk_issue_desc'])) {
            return $this->handleIssueFlow($chatId, $session, $text);
        }

        // Commands
        if ($text === '/start')    return $this->showWelcome($chatId, $session);
        if ($text === '/status')   return $this->showStatus($chatId);
        if ($text === '/issues')   return $this->showIssues($chatId);
        if ($text === '/alldirty') return $this->markAllDirty($chatId, $session);
        if ($text === '/logout') {
            $session->update(['user_id' => null, 'state' => 'idle', 'data' => null]);
            $this->send($chatId, "Chiqildi. Qayta kirish uchun telefon raqamingizni yuboring.", $this->phoneKb());
            return response('OK');
        }

        // /dirty 7 or /iflos 7 — managers only
        if (preg_match('/^\/(dirty|iflos)\s+(\d+)$/i', $text, $m)) {
            return $this->markDirty($chatId, $session, (int) $m[2]);
        }

        // Parse room numbers from message: "7", "3,5,11", "7 xona tayyor", "11 14"
        $rooms = $this->extractRoomNumbers($text);
        if (!empty($rooms)) {
            return $this->markRoomsClean($chatId, $session, $rooms);
        }

        // Unknown input — show brief hint
        $this->send($chatId, "Xona raqamini yozing (masalan: 7 yoki 3,5,11)\n📸 Rasm yuboring — muammo haqida\n/status — barcha xonalar");
        return response('OK');
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

        $this->send($chatId, "Xush kelibsiz, {$user->name}!");
        return $this->showWelcome($chatId, TelegramPosSession::where('chat_id', $chatId)->first());
    }

    // ── WELCOME ──────────────────────────────────────────────────

    protected function showWelcome(int $chatId, $session)
    {
        $session->update(['state' => 'hk_main', 'data' => null]);

        $text = "🧹 <b>Jahongir Hotel — Tozalik Bot</b>\n\n"
            . "Xona raqamini yozing — toza deb belgilanadi\n"
            . "Masalan: <code>7</code> yoki <code>3,5,11</code>\n\n"
            . "📸 Rasm yuboring — muammo haqida xabar\n"
            . "/status — barcha xonalar holati\n"
            . "/issues — ochiq muammolar";

        $this->send($chatId, $text);
        return response('OK');
    }

    // ── MARK ROOMS CLEAN ─────────────────────────────────────────

    protected function markRoomsClean(int $chatId, $session, array $rooms)
    {
        $user    = User::find($session->user_id);
        $cleaned = [];

        foreach ($rooms as $num) {
            $roomStatus = RoomStatus::where('room_number', $num)->first();
            if (!$roomStatus) continue;

            $roomStatus->markClean($session->user_id);
            $cleaned[] = $num;

            Log::info('HousekeepingBot: room marked clean', [
                'room_number' => $num,
                'user_id'     => $session->user_id,
                'user_name'   => $user?->name,
            ]);
        }

        if (empty($cleaned)) {
            $this->send($chatId, "Xona topilmadi. 1 dan 15 gacha raqam kiriting.");
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

        $this->send($chatId, $text);
        return response('OK');
    }

    // ── MARK SINGLE ROOM DIRTY (manager) ─────────────────────────

    protected function markDirty(int $chatId, $session, int $roomNum)
    {
        $user = User::find($session->user_id);

        // Check manager role
        if (!$user || !in_array($user->role, ['admin', 'manager', 'owner'])) {
            $this->send($chatId, "Bu buyruq faqat rahbariyat uchun.");
            return response('OK');
        }

        $roomStatus = RoomStatus::where('room_number', $roomNum)->first();
        if (!$roomStatus) {
            $this->send($chatId, "Xona topilmadi. 1-15 oralig'ida raqam kiriting.");
            return response('OK');
        }

        $roomStatus->markDirty();

        Log::info('HousekeepingBot: room marked dirty', [
            'room_number' => $roomNum,
            'user_id'     => $session->user_id,
            'user_name'   => $user?->name,
        ]);

        $this->send($chatId, "🟡 {$roomNum}-xona — Iflos deb belgilandi.");
        return response('OK');
    }

    // ── MARK ALL DIRTY (manager) ──────────────────────────────────

    protected function markAllDirty(int $chatId, $session)
    {
        $user = User::find($session->user_id);

        if (!$user || !in_array($user->role, ['admin', 'manager', 'owner'])) {
            $this->send($chatId, "Bu buyruq faqat rahbariyat uchun.");
            return response('OK');
        }

        RoomStatus::query()->update([
            'status'     => 'dirty',
            'cleaned_by' => null,
            'cleaned_at' => null,
            'updated_at' => now(),
        ]);

        Log::info('HousekeepingBot: all rooms marked dirty', [
            'user_id'   => $session->user_id,
            'user_name' => $user?->name,
        ]);

        $this->send($chatId, "🟡 Barcha 15 ta xona — Iflos deb belgilandi.");
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

        $this->send($chatId, "✅ Muammo saqlandi! Rahbariyatga xabar berildi.");
        return response('OK');
    }

    // ── STATUS OVERVIEW ──────────────────────────────────────────

    protected function showStatus(int $chatId)
    {
        $statuses = RoomStatus::orderBy('room_number')->get();

        $clean = $statuses->where('status', 'clean')->pluck('room_number')->toArray();
        $dirty = $statuses->where('status', 'dirty')->pluck('room_number')->toArray();

        $cleanStr = !empty($clean) ? implode(', ', $clean) : 'yo\'q';
        $dirtyStr = !empty($dirty) ? implode(', ', $dirty) : 'yo\'q';

        $todayCount = RoomCleaning::whereDate('cleaned_at', today())->count();

        $text = "🏨 <b>Jahongir Hotel</b>\n\n"
            . "✅ Toza: {$cleanStr}\n"
            . "🟡 Iflos: {$dirtyStr}\n\n"
            . "📊 Bugun: {$todayCount} ta xona tozalandi";

        $this->send($chatId, $text);
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

        foreach ($openIssues as $issue) {
            $age  = $issue->created_at->diffForHumans(now(), true);
            $desc = $issue->description ? mb_substr($issue->description, 0, 40) : 'Tavsif yo\'q';
            $lines[] = "📍 {$issue->room_number}-xona: {$desc} ({$age})";
        }

        $this->send($chatId, implode("\n", $lines));
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

        try {
            $resp = Http::timeout(15)->post(
                "https://api.telegram.org/bot{$this->botToken}/sendPhoto",
                [
                    'chat_id'    => $this->mgmtGroupId,
                    'photo'      => $fileId,
                    'caption'    => $caption,
                    'parse_mode' => 'HTML',
                ]
            );

            if (!$resp->successful()) {
                Log::warning('HousekeepingBot: failed to forward issue to management group', [
                    'status' => $resp->status(),
                    'body'   => $resp->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('HousekeepingBot: error forwarding issue', ['error' => $e->getMessage()]);
        }
    }

    // ── HELPERS ──────────────────────────────────────────────────

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
    protected function downloadTelegramPhoto(string $fileId): ?string
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
            $filename = 'room-issues/' . date('Y/m') . '/' . uniqid('issue_', true) . '.' . $ext;

            Storage::disk('public')->put($filename, $fileResp->body());

            return $filename;
        } catch (\Throwable $e) {
            Log::error('HousekeepingBot: downloadTelegramPhoto error', ['error' => $e->getMessage()]);
            return null;
        }
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
