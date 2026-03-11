<?php

namespace App\Http\Controllers;

use App\Models\RoomStatus;
use App\Models\TelegramPosSession;
use App\Models\User;
use App\Services\OwnerAlertService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HousekeepingBotController extends Controller
{
    protected string $botToken;
    protected OwnerAlertService $ownerAlert;

    // Property definitions
    protected array $properties = [
        41097  => '🏨 Jahongir Hotel',
        172793 => '🏨 Jahongir Premium',
    ];

    // Room types per property [room_id => room_name]
    protected array $roomsByProperty = [
        41097 => [
            94982  => 'Double Room',
            94984  => 'Single Room',
            94986  => 'Twin Room',
            94991  => 'Large Double Room',
            97215  => 'Family Room',
            144341 => 'Twin/Double',
            144342 => 'Junior Suite',
            152726 => '1 xona',
        ],
        172793 => [
            377291 => 'Double or Twin',
            377298 => 'Deluxe Single',
            377299 => 'Standard Queen',
            377300 => 'Standard Double',
            377301 => 'Deluxe Double/Twin',
            377302 => 'Superior Double',
            377303 => 'Superior Double/Twin',
            377304 => 'Deluxe Triple',
        ],
    ];

    public function __construct(OwnerAlertService $ownerAlert)
    {
        $this->botToken = config('services.housekeeping_bot.token', '');
        $this->ownerAlert = $ownerAlert;
    }

    // ── WEBHOOK ENTRY ────────────────────────────────────────────

    public function handleWebhook(Request $request)
    {
        try {
            Log::debug('HousekeepingBot webhook', ['data' => $request->all()]);
            if ($callback = $request->input('callback_query')) return $this->handleCallback($callback);
            if ($message = $request->input('message')) return $this->handleMessage($message);
            return response('OK');
        } catch (\Throwable $e) {
            Log::error('HousekeepingBot unhandled error', ['e' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
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
        if ($contact) return $this->handleAuth($chatId, $contact);

        $session = TelegramPosSession::where('chat_id', $chatId)->first();

        if (!$session || !$session->user_id) {
            $this->send($chatId, "Отправьте номер телефона для авторизации.", $this->phoneKb());
            return response('OK');
        }

        // Expire idle sessions only
        if ($session->isExpired() && in_array($session->state, ['hk_main', 'idle', null])) {
            $session->update(['user_id' => null, 'state' => 'idle', 'data' => null]);
            $this->send($chatId, "Сессия истекла. Отправьте номер телефона.", $this->phoneKb());
            return response('OK');
        }

        $session->updateActivity();

        if ($text === '/start' || $text === '/menu') return $this->showMainMenu($chatId, $session);
        if ($text === '/logout') {
            $session->update(['user_id' => null, 'state' => 'idle', 'data' => null]);
            $this->send($chatId, "Вы вышли. Отправьте номер телефона для входа.", $this->phoneKb());
            return response('OK');
        }

        // Default: show main menu for any unrecognised text
        return $this->showMainMenu($chatId, $session);
    }

    // ── AUTH ─────────────────────────────────────────────────────

    protected function handleAuth(int $chatId, array $contact)
    {
        $phone = preg_replace('/[^0-9]/', '', $contact['phone_number'] ?? '');
        $user  = User::where('phone_number', 'LIKE', '%' . substr($phone, -9))->first();

        if (!$user) {
            $this->send($chatId, "Номер не найден. Обратитесь к руководству.");
            return response('OK');
        }

        TelegramPosSession::updateOrCreate(
            ['chat_id' => $chatId],
            ['user_id' => $user->id, 'state' => 'hk_main', 'data' => null]
        );

        $this->send($chatId, "Добро пожаловать, {$user->name}!");
        return $this->showMainMenu($chatId, TelegramPosSession::where('chat_id', $chatId)->first());
    }

    // ── MAIN MENU ────────────────────────────────────────────────

    protected function showMainMenu(int $chatId, $session)
    {
        $session->update(['state' => 'hk_main', 'data' => null]);

        $btns = [
            [['text' => '🏨 Jahongir Hotel',   'callback_data' => 'hk_prop_41097']],
            [['text' => '🏨 Jahongir Premium', 'callback_data' => 'hk_prop_172793']],
            [['text' => '📊 Обзор статусов',   'callback_data' => 'hk_overview']],
        ];

        $this->send($chatId, "Горничная-бот\n\nВыберите объект:", ['inline_keyboard' => $btns], 'inline');
        return response('OK');
    }

    // ── CALLBACK HANDLER ─────────────────────────────────────────

    protected function handleCallback(array $cb)
    {
        $chatId    = $cb['message']['chat']['id'] ?? null;
        $msgId     = $cb['message']['message_id'] ?? null;
        $data      = $cb['data'] ?? '';
        $cbId      = $cb['id'] ?? '';

        if (!$chatId) return response('OK');
        $this->aCb($cbId);

        $session = TelegramPosSession::where('chat_id', $chatId)->first();
        if (!$session || !$session->user_id) {
            $this->send($chatId, "Отправьте номер телефона для авторизации.", $this->phoneKb());
            return response('OK');
        }

        $session->updateActivity();

        return match(true) {
            $data === 'hk_menu'                    => $this->showMainMenu($chatId, $session),
            $data === 'hk_overview'                => $this->showOverview($chatId, $msgId, $session),
            str_starts_with($data, 'hk_prop_')    => $this->showRoomTypes($chatId, $msgId, $session, $data),
            str_starts_with($data, 'hk_room_')    => $this->showUnits($chatId, $msgId, $session, $data),
            str_starts_with($data, 'hk_unit_')    => $this->showUnitStatus($chatId, $msgId, $session, $data),
            str_starts_with($data, 'hk_set_')     => $this->setStatus($chatId, $msgId, $session, $data),
            default                                 => response('OK'),
        };
    }

    // ── SELECT PROPERTY → SHOW ROOM TYPES ───────────────────────

    protected function showRoomTypes(int $chatId, ?int $msgId, $session, string $data)
    {
        $propertyId = (int) str_replace('hk_prop_', '', $data);

        if (!isset($this->roomsByProperty[$propertyId])) {
            $this->send($chatId, "Объект не найден.");
            return response('OK');
        }

        $session->update(['state' => 'hk_select_room', 'data' => ['property_id' => $propertyId]]);

        $rooms = $this->roomsByProperty[$propertyId];
        $btns  = [];

        foreach ($rooms as $roomId => $roomName) {
            // Aggregate status for this room type (worst-case: repair > dirty > clean)
            $statuses = RoomStatus::where('beds24_property_id', $propertyId)
                ->where('beds24_room_id', $roomId)
                ->pluck('status')
                ->toArray();

            $emoji = $this->aggregateEmoji($statuses);
            $btns[] = [['text' => "{$emoji} {$roomName}", 'callback_data' => "hk_room_{$propertyId}_{$roomId}"]];
        }

        $btns[] = [['text' => '⬅️ Назад', 'callback_data' => 'hk_menu']];

        $propertyName = $this->properties[$propertyId] ?? 'Объект';
        $text = "{$propertyName}\n\nВыберите тип номера:";

        $this->editOrSend($chatId, $msgId, $text, ['inline_keyboard' => $btns]);
        return response('OK');
    }

    // ── SELECT ROOM TYPE → SHOW UNITS ────────────────────────────

    protected function showUnits(int $chatId, ?int $msgId, $session, string $data)
    {
        // Format: hk_room_{propertyId}_{roomId}
        $parts      = explode('_', $data);
        $propertyId = (int) $parts[2];
        $roomId     = (int) $parts[3];

        $session->update(['state' => 'hk_select_unit', 'data' => [
            'property_id' => $propertyId,
            'room_id'     => $roomId,
        ]]);

        $roomName = $this->roomsByProperty[$propertyId][$roomId] ?? 'Номер';
        $units    = RoomStatus::where('beds24_property_id', $propertyId)
            ->where('beds24_room_id', $roomId)
            ->orderBy('unit_number')
            ->get();

        if ($units->isEmpty()) {
            $this->send($chatId, "Юниты для этого типа номера не найдены.");
            return response('OK');
        }

        $btns = [];
        foreach ($units as $unit) {
            $label = $unit->unit_name ?: "Комната {$unit->unit_number}";
            $btns[] = [[
                'text'          => "{$unit->statusEmoji()} {$label}",
                'callback_data' => "hk_unit_{$propertyId}_{$roomId}_{$unit->unit_number}",
            ]];
        }
        $btns[] = [['text' => '⬅️ Назад', 'callback_data' => "hk_prop_{$propertyId}"]];

        $text = "{$this->properties[$propertyId]}\n{$roomName}\n\nВыберите комнату:";
        $this->editOrSend($chatId, $msgId, $text, ['inline_keyboard' => $btns]);
        return response('OK');
    }

    // ── SELECT UNIT → SHOW STATUS + ACTION BUTTONS ───────────────

    protected function showUnitStatus(int $chatId, ?int $msgId, $session, string $data)
    {
        // Format: hk_unit_{propertyId}_{roomId}_{unitNumber}
        $parts      = explode('_', $data);
        $propertyId = (int) $parts[2];
        $roomId     = (int) $parts[3];
        $unitNumber = (int) $parts[4];

        $unit = RoomStatus::where('beds24_property_id', $propertyId)
            ->where('beds24_room_id', $roomId)
            ->where('unit_number', $unitNumber)
            ->first();

        if (!$unit) {
            $this->send($chatId, "Комната не найдена.");
            return response('OK');
        }

        $session->update(['state' => 'hk_set_status', 'data' => [
            'property_id' => $propertyId,
            'room_id'     => $roomId,
            'unit_number' => $unitNumber,
        ]]);

        $roomName = $this->roomsByProperty[$propertyId][$roomId] ?? 'Номер';
        $unitLabel = $unit->unit_name ?: "Комната {$unitNumber}";

        $text = "{$this->properties[$propertyId]}\n"
            . "{$roomName} — {$unitLabel}\n\n"
            . "Текущий статус: {$unit->statusEmoji()} <b>{$unit->statusLabel()}</b>\n\n"
            . "Изменить статус:";

        $btns = [
            [
                ['text' => '✅ Чистый',  'callback_data' => "hk_set_{$propertyId}_{$roomId}_{$unitNumber}_clean"],
                ['text' => '🟡 Грязный', 'callback_data' => "hk_set_{$propertyId}_{$roomId}_{$unitNumber}_dirty"],
                ['text' => '🔴 Ремонт',  'callback_data' => "hk_set_{$propertyId}_{$roomId}_{$unitNumber}_repair"],
            ],
            [['text' => '⬅️ Назад', 'callback_data' => "hk_room_{$propertyId}_{$roomId}"]],
        ];

        $this->editOrSend($chatId, $msgId, $text, ['inline_keyboard' => $btns]);
        return response('OK');
    }

    // ── SET STATUS ───────────────────────────────────────────────

    protected function setStatus(int $chatId, ?int $msgId, $session, string $data)
    {
        // Format: hk_set_{propertyId}_{roomId}_{unitNumber}_{status}
        $parts      = explode('_', $data);
        $propertyId = (int) $parts[2];
        $roomId     = (int) $parts[3];
        $unitNumber = (int) $parts[4];
        $newStatus  = $parts[5] ?? '';

        if (!in_array($newStatus, ['clean', 'dirty', 'repair'])) {
            $this->send($chatId, "Неверный статус.");
            return response('OK');
        }

        $unit = RoomStatus::where('beds24_property_id', $propertyId)
            ->where('beds24_room_id', $roomId)
            ->where('unit_number', $unitNumber)
            ->first();

        if (!$unit) {
            $this->send($chatId, "Комната не найдена.");
            return response('OK');
        }

        $oldStatus = $unit->status;
        $unit->update(['status' => $newStatus, 'updated_by' => $session->user_id]);

        $roomName  = $this->roomsByProperty[$propertyId][$roomId] ?? 'Номер';
        $unitLabel = $unit->unit_name ?: "Комната {$unitNumber}";
        $user      = User::find($session->user_id);

        Log::info('HousekeepingBot: status changed', [
            'property_id' => $propertyId,
            'room_id'     => $roomId,
            'unit_number' => $unitNumber,
            'old_status'  => $oldStatus,
            'new_status'  => $newStatus,
            'user_id'     => $session->user_id,
            'user_name'   => $user?->name,
        ]);

        // Reload unit to get fresh data
        $unit->refresh();

        $text = "{$this->properties[$propertyId]}\n"
            . "{$roomName} — {$unitLabel}\n\n"
            . "✅ Статус обновлён!\n"
            . "Текущий статус: {$unit->statusEmoji()} <b>{$unit->statusLabel()}</b>\n\n"
            . "Изменить статус:";

        $btns = [
            [
                ['text' => '✅ Чистый',  'callback_data' => "hk_set_{$propertyId}_{$roomId}_{$unitNumber}_clean"],
                ['text' => '🟡 Грязный', 'callback_data' => "hk_set_{$propertyId}_{$roomId}_{$unitNumber}_dirty"],
                ['text' => '🔴 Ремонт',  'callback_data' => "hk_set_{$propertyId}_{$roomId}_{$unitNumber}_repair"],
            ],
            [['text' => '⬅️ Назад', 'callback_data' => "hk_room_{$propertyId}_{$roomId}"]],
        ];

        $this->editOrSend($chatId, $msgId, $text, ['inline_keyboard' => $btns]);
        return response('OK');
    }

    // ── STATUS OVERVIEW ──────────────────────────────────────────

    protected function showOverview(int $chatId, ?int $msgId, $session)
    {
        $lines = ["📊 <b>Обзор статусов</b>\n"];

        foreach ($this->properties as $propertyId => $propertyName) {
            $stats = RoomStatus::where('beds24_property_id', $propertyId)
                ->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray();

            $clean  = $stats['clean']  ?? 0;
            $dirty  = $stats['dirty']  ?? 0;
            $repair = $stats['repair'] ?? 0;
            $total  = $clean + $dirty + $repair;

            $lines[] = "<b>{$propertyName}</b>";
            $lines[] = "✅ Чистых: {$clean}";
            $lines[] = "🟡 Грязных: {$dirty}";
            $lines[] = "🔴 Ремонт: {$repair}";
            $lines[] = "Всего: {$total}";
            $lines[] = '';
        }

        $text = implode("\n", $lines);
        $btns = [
            [['text' => '🔄 Обновить',        'callback_data' => 'hk_overview']],
            [['text' => '⬅️ Главное меню',    'callback_data' => 'hk_menu']],
        ];

        $this->editOrSend($chatId, $msgId, $text, ['inline_keyboard' => $btns]);
        return response('OK');
    }

    // ── HELPERS ──────────────────────────────────────────────────

    /**
     * Aggregate status emoji for a room type:
     * If any unit is 'repair' → 🔴
     * If any unit is 'dirty'  → 🟡
     * All clean               → ✅
     */
    protected function aggregateEmoji(array $statuses): string
    {
        if (in_array('repair', $statuses)) return '🔴';
        if (in_array('dirty', $statuses))  return '🟡';
        return '✅';
    }

    protected function phoneKb(): array
    {
        return [
            'keyboard'         => [[['text' => 'Отправить номер', 'request_contact' => true]]],
            'resize_keyboard'  => true,
            'one_time_keyboard'=> true,
        ];
    }

    protected function send(int $chatId, string $text, ?array $kb = null, string $type = 'reply')
    {
        $p = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'];
        if ($kb) $p['reply_markup'] = json_encode($kb);
        try {
            $resp = Http::timeout(10)->post("https://api.telegram.org/bot{$this->botToken}/sendMessage", $p);
            if (!$resp->successful()) {
                Log::warning('HousekeepingBot send failed', ['chat' => $chatId, 'status' => $resp->status(), 'body' => $resp->body()]);
            }
        } catch (\Throwable $e) {
            Log::error('HousekeepingBot send error', ['chat' => $chatId, 'error' => $e->getMessage()]);
        }
    }

    protected function editOrSend(int $chatId, ?int $msgId, string $text, array $kb)
    {
        $payload = [
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'HTML',
            'reply_markup' => json_encode($kb),
        ];

        if ($msgId) {
            $payload['message_id'] = $msgId;
            try {
                $resp = Http::timeout(10)->post(
                    "https://api.telegram.org/bot{$this->botToken}/editMessageText",
                    $payload
                );
                if ($resp->successful()) return;
                // If edit fails (message too old), fall through to sendMessage
            } catch (\Throwable $e) {
                Log::warning('HousekeepingBot editMessageText failed', ['error' => $e->getMessage()]);
            }
        }

        // Fallback to send new message
        unset($payload['message_id']);
        try {
            Http::timeout(10)->post("https://api.telegram.org/bot{$this->botToken}/sendMessage", $payload);
        } catch (\Throwable $e) {
            Log::error('HousekeepingBot send error', ['chat' => $chatId, 'error' => $e->getMessage()]);
        }
    }

    protected function aCb(string $id): void
    {
        if (!$id) return;
        Http::post("https://api.telegram.org/bot{$this->botToken}/answerCallbackQuery", ['callback_query_id' => $id]);
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
