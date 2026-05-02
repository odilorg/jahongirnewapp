<?php

namespace App\Services;

use App\Models\Beds24Booking;
use App\Models\Beds24BookingChange;
use App\Models\CashTransaction;
use App\Models\CashierShift;
use App\Enums\TransactionType;
use App\Enums\Currency;
use App\Jobs\SendTelegramNotificationJob;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class OwnerAlertService
{
    protected int $ownerChatId;

    public function __construct()
    {
        $this->ownerChatId = (int) config('services.owner_alert_bot.owner_chat_id', env('OWNER_TELEGRAM_ID', '0'));
    }

    // -------------------------------------------------------------------------
    // Public alert methods
    // -------------------------------------------------------------------------

    /**
     * New booking received
     */
    public function alertNewBooking(Beds24Booking $booking, Beds24BookingChange $change): void
    {
        $text = $this->buildNewBookingMessage($booking);
        $this->send($text);
        $change->markAlerted();
    }

    /**
     * New booking that arrived already paid — single combined message
     */
    public function alertNewBookingWithPayment(Beds24Booking $booking, Beds24BookingChange $change, array $paymentLines = []): void
    {
        $currency = $booking->currency;
        $paymentSection = '';

        if (!empty($paymentLines)) {
            $lines = [];
            foreach ($paymentLines as $line) {
                $desc = $line['description'] ?? '?';
                $amt = (float) ($line['amount'] ?? 0);
                $method = $line['status'] ?? '';
                $methodLabel = match(strtolower($method)) {
                    'naqd' => 'наличные',
                    'plastk', 'plastik', 'card' => 'карта',
                    'perevod', 'transfer' => 'перевод',
                    default => $method ?: '?',
                };
                $lines[] = "  • {$desc}: {$amt} {$currency} ({$methodLabel})";
            }
            $paymentSection = "\n<b>Платежи:</b>\n" . implode("\n", $lines) . "\n";
        }

        $text = implode("\n", [
            "🟢💰 <b>Новое бронирование (оплачено)</b>",
            "",
            "🏨 <b>Объект:</b> {$booking->getPropertyName()}",
            "🆔 <b>Бронирование:</b> #{$booking->beds24_booking_id}",
            "👤 <b>Гость:</b> {$booking->guest_name}",
            "📞 <b>Телефон:</b> " . ($booking->guest_phone ?: 'не указан'),
            "✉️ <b>Email:</b> " . ($booking->guest_email ?: 'не указан'),
            "🌐 <b>Канал:</b> " . ($booking->channel ?: 'прямое'),
            "📅 <b>Заезд:</b> {$this->formatDate($booking->arrival_date)}",
            "📅 <b>Выезд:</b> {$this->formatDate($booking->departure_date)}",
            "🌙 <b>Ночей:</b> {$booking->nights}",
            "👥 <b>Гостей:</b> {$booking->num_adults} взрослых" . ($booking->num_children > 0 ? ", {$booking->num_children} детей" : ''),
            "🛏️ <b>Комната:</b> " . ($booking->room_name ?: 'не указана'),
            "💰 <b>Сумма:</b> {$booking->total_amount} {$currency}",
            "✅ <b>Статус:</b> Полностью оплачено",
            $paymentSection,
            "⏰ " . now('Asia/Tashkent')->format('d.m.Y H:i'),
        ]);

        $this->send($text);
        $change->markAlerted();
    }

    /**
     * Booking was cancelled
     */
    public function alertCancellation(Beds24Booking $booking, Beds24BookingChange $change): void
    {
        $text = $this->buildCancellationMessage($booking);
        $this->send($text);
        $change->markAlerted();
    }

    /**
     * Booking amount was reduced (suspicious — potential fraud)
     */
    public function alertAmountReduced(Beds24Booking $booking, Beds24BookingChange $change, float $oldAmount, float $newAmount): void
    {
        $diff = $oldAmount - $newAmount;
        $currency = $booking->currency;

        $text = implode("\n", [
            "🔴 <b>КРИТИЧНО: Сумма бронирования снижена!</b>",
            "",
            "🏨 <b>Объект:</b> {$booking->getPropertyName()}",
            "🆔 <b>Бронирование:</b> #{$booking->beds24_booking_id}",
            "👤 <b>Гость:</b> {$booking->guest_name}",
            "📅 <b>Заезд:</b> {$this->formatDate($booking->arrival_date)}",
            "📅 <b>Выезд:</b> {$this->formatDate($booking->departure_date)}",
            "",
            "💰 <b>Старая сумма:</b> {$oldAmount} {$currency}",
            "💰 <b>Новая сумма:</b> {$newAmount} {$currency}",
            "⚠️ <b>Разница:</b> -{$diff} {$currency}",
            "",
            "⏰ " . now('Asia/Tashkent')->format('d.m.Y H:i'),
        ]);

        $this->send($text);
        $change->markAlerted();
    }

    /**
     * Booking was modified (dates, guests, etc.)
     */
    public function alertModification(Beds24Booking $booking, Beds24BookingChange $change, array $changedFields): void
    {
        $fieldLines = [];
        foreach ($changedFields as $field => $values) {
            $fieldLines[] = "  • <b>{$field}:</b> {$values['old']} → {$values['new']}";
        }
        $fieldsText = implode("\n", $fieldLines);

        $text = implode("\n", [
            "🟡 <b>ИЗМЕНЕНИЕ бронирования</b>",
            "",
            "🏨 <b>Объект:</b> {$booking->getPropertyName()}",
            "🆔 <b>Бронирование:</b> #{$booking->beds24_booking_id}",
            "👤 <b>Гость:</b> {$booking->guest_name}",
            "📅 <b>Заезд:</b> {$this->formatDate($booking->arrival_date)}",
            "",
            "<b>Изменённые поля:</b>",
            $fieldsText,
            "",
            "⏰ " . now('Asia/Tashkent')->format('d.m.Y H:i'),
        ]);

        $this->send($text);
        $change->markAlerted();
    }

    /**
     * Cancelled booking that had already checked in (very suspicious)
     */
    public function alertCancelledAfterCheckin(Beds24Booking $booking, Beds24BookingChange $change): void
    {
        $text = implode("\n", [
            "🔴 <b>КРИТИЧНО: Бронирование отменено ПОСЛЕ заезда!</b>",
            "",
            "🏨 <b>Объект:</b> {$booking->getPropertyName()}",
            "🆔 <b>Бронирование:</b> #{$booking->beds24_booking_id}",
            "👤 <b>Гость:</b> {$booking->guest_name}",
            "📞 <b>Телефон:</b> " . ($booking->guest_phone ?: 'не указан'),
            "✉️ <b>Email:</b> " . ($booking->guest_email ?: 'не указан'),
            "📅 <b>Заезд:</b> {$this->formatDate($booking->arrival_date)}",
            "📅 <b>Выезд:</b> {$this->formatDate($booking->departure_date)}",
            "💰 <b>Сумма:</b> {$booking->total_amount} {$booking->currency}",
            "🌐 <b>Канал:</b> " . ($booking->channel ?: 'не указан'),
            "",
            "⚠️ Немедленно проверьте наличие гостя в отеле!",
            "",
            "⏰ " . now('Asia/Tashkent')->format('d.m.Y H:i'),
        ]);

        $this->send($text);
        $change->markAlerted();
    }


    /**
     * Payment received (auto-synced from Beds24)
     */
    public function alertPaymentReceived(
        Beds24Booking $booking,
        Beds24BookingChange $change,
        float $paymentAmount,
        float $oldBalance,
        float $newBalance
    ): void {
        $currency = $booking->currency;
        $isPaidInFull = $newBalance <= 0;

        $statusLine = $isPaidInFull
            ? "✅ <b>Полностью оплачено!</b>"
            : "⚠️ <b>Остаток:</b> {$newBalance} {$currency}";

        $text = implode("\n", [
            "💰 <b>Оплата получена</b>",
            "",
            "🏨 <b>Объект:</b> {$booking->getPropertyName()}",
            "\xF0\x9F\x86\x94 <b>Бронирование:</b> #{$booking->beds24_booking_id}",
            "👤 <b>Гость:</b> {$booking->guest_name}",
            "🛏️ <b>Комната:</b> " . ($booking->room_name ?: 'не указана'),
            "📅 <b>Заезд:</b> {$this->formatDate($booking->arrival_date)}",
            "",
            "\xF0\x9F\x92\xB5 <b>Сумма оплаты:</b> {$paymentAmount} {$currency}",
            "💰 <b>Всего по брони:</b> {$booking->total_amount} {$currency}",
            $statusLine,
            "",
            "⏰ " . now('Asia/Tashkent')->format('d.m.Y H:i'),
        ]);

        $this->send($text);
        $change->markAlerted();
    }


    /**
     * Payment received with full line details from Beds24 API
     */
    public function alertPaymentWithDetails(
        Beds24Booking $booking,
        Beds24BookingChange $change,
        array $paymentLines,
        float $oldBalance,
        float $newBalance
    ): void {
        $currency = $booking->currency;
        $totalPaid = array_sum(array_map(fn($l) => (float) ($l['amount'] ?? 0), $paymentLines));

        $lines = [];
        foreach ($paymentLines as $line) {
            $desc = $line['description'] ?? '?';
            $amt = (float) ($line['amount'] ?? 0);
            $method = $line['status'] ?? '';
            $methodLabel = match(strtolower($method)) {
                'naqd' => 'наличные',
                'plastk', 'plastik', 'card' => 'карта',
                'perevod', 'transfer' => 'перевод',
                default => $method ?: '?',
            };
            $lines[] = "  • {$desc}: {$amt} {$currency} ({$methodLabel})";
        }
        $linesText = implode("\n", $lines);

        $isPaidInFull = $newBalance <= 0;
        $statusLine = $isPaidInFull
            ? "✅ Полностью оплачено!"
            : "⚠️ Остаток: {$newBalance} {$currency}";

        $text = implode("\n", [
            "💰 <b>Оплата получена</b>",
            "",
            "🏨 <b>Объект:</b> {$booking->getPropertyName()}",
            "🆔 <b>Бронирование:</b> #{$booking->beds24_booking_id}",
            "👤 <b>Гость:</b> {$booking->guest_name}",
            "🛏️ <b>Комната:</b> " . ($booking->room_name ?: 'не указана'),
            "📅 <b>Заезд:</b> {$this->formatDate($booking->arrival_date)}",
            "",
            "<b>Платежи:</b>",
            $linesText,
            "",
            "💵 <b>Итого оплачено:</b> {$totalPaid} {$currency}",
            "💰 <b>Всего по брони:</b> {$booking->total_amount} {$currency}",
            $statusLine,
            "",
            "⏰ " . now('Asia/Tashkent')->format('d.m.Y H:i'),
        ]);

        $this->send($text);
        $change->markAlerted();
    }

    /**
     * New charge items added to a booking (e.g. taxi, minibar, extra services)
     */
    public function alertNewCharge(Beds24Booking $booking, Beds24BookingChange $change, array $newCharges, array $raw = []): void
    {
        $currency = $booking->currency;

        // Build charge lines
        $totalNewCharges = 0;
        $lines = [];
        foreach ($newCharges as $charge) {
            $desc = $charge['description'] ?? '?';
            $amount = (float) ($charge['lineTotal'] ?? $charge['amount'] ?? 0);
            $totalNewCharges += $amount;
            $lines[] = "  • {$desc}: {$amount} {$currency}";
        }
        $linesText = implode("\n", $lines);

        // Calculate totals from all invoiceItems
        $allItems = $raw['invoiceItems'] ?? $raw['booking']['invoiceItems'] ?? [];
        $totalCharges = 0;
        $totalPayments = 0;
        foreach ($allItems as $item) {
            $lt = (float) ($item['lineTotal'] ?? 0);
            if ($lt >= 0) {
                $totalCharges += $lt;
            } else {
                $totalPayments += abs($lt);
            }
        }
        $balance = max(0, $totalCharges - $totalPayments);

        $balanceLine = $balance <= 0.01
            ? "✅ Полностью оплачено"
            : "⚠️ Остаток к оплате: {$balance} {$currency}";

        $text = implode("\n", [
            "📝 <b>Новый расход добавлен</b>",
            "",
            "🏨 <b>Объект:</b> {$booking->getPropertyName()}",
            "🆔 <b>Бронирование:</b> #{$booking->beds24_booking_id}",
            "👤 <b>Гость:</b> {$booking->guest_name}",
            "🛏️ <b>Комната:</b> " . ($booking->room_name ?: 'не указана'),
            "📅 <b>Заезд:</b> {$this->formatDate($booking->arrival_date)}",
            "",
            "<b>Новые расходы:</b>",
            $linesText,
            "",
            "💵 <b>Сумма новых расходов:</b> {$totalNewCharges} {$currency}",
            "💰 <b>Итого расходов:</b> {$totalCharges} {$currency}",
            "💳 <b>Оплачено:</b> {$totalPayments} {$currency}",
            $balanceLine,
            "",
            "⏰ " . now('Asia/Tashkent')->format('d.m.Y H:i'),
        ]);

        $this->send($text);
        $change->markAlerted();
    }

    /**
     * Daily summary report sent at 22:00 Tashkent time
     */
    public function sendDailySummary(array $stats): void
    {
        $date = now('Asia/Tashkent')->format('d.m.Y');

        $hotelStats  = $stats['41097']  ?? [];
        $premiumStats = $stats['172793'] ?? [];

        $text = implode("\n", [
            "📊 <b>Ежедневный отчёт — {$date}</b>",
            "",
            "🏨 <b>Jahongir Hotel</b>",
            "  • Новых бронирований: " . ($hotelStats['new_bookings'] ?? 0),
            "  • Отменено: " . ($hotelStats['cancellations'] ?? 0),
            "  • Изменено: " . ($hotelStats['modifications'] ?? 0),
            "  • Текущих гостей: " . ($hotelStats['current_guests'] ?? 0),
            "  • Ожидается завтра: " . ($hotelStats['arrivals_tomorrow'] ?? 0),
            "  • Выезжает завтра: " . ($hotelStats['departures_tomorrow'] ?? 0),
            "  • Сумма за день: " . ($hotelStats['revenue_today'] ?? '0') . " " . ($hotelStats['currency'] ?? 'USD'),
            "",
            "⭐ <b>Jahongir Premium</b>",
            "  • Новых бронирований: " . ($premiumStats['new_bookings'] ?? 0),
            "  • Отменено: " . ($premiumStats['cancellations'] ?? 0),
            "  • Изменено: " . ($premiumStats['modifications'] ?? 0),
            "  • Текущих гостей: " . ($premiumStats['current_guests'] ?? 0),
            "  • Ожидается завтра: " . ($premiumStats['arrivals_tomorrow'] ?? 0),
            "  • Выезжает завтра: " . ($premiumStats['departures_tomorrow'] ?? 0),
            "  • Сумма за день: " . ($premiumStats['revenue_today'] ?? '0') . " " . ($premiumStats['currency'] ?? 'USD'),
            "",
            "🔔 Неоплаченных броней: " . ($stats['unpaid_count'] ?? 0),
            "",
            "⏰ Сформировано: " . now('Asia/Tashkent')->format('d.m.Y H:i'),
        ]);

        $this->send($text);
    }

    // -------------------------------------------------------------------------
    // Private message builders
    // -------------------------------------------------------------------------

    private function buildNewBookingMessage(Beds24Booking $booking): string
    {
        return implode("\n", [
            "🟢 <b>Новое бронирование</b>",
            "",
            "🏨 <b>Объект:</b> {$booking->getPropertyName()}",
            "🆔 <b>Бронирование:</b> #{$booking->beds24_booking_id}",
            "👤 <b>Гость:</b> {$booking->guest_name}",
            "📞 <b>Телефон:</b> " . ($booking->guest_phone ?: 'не указан'),
            "✉️ <b>Email:</b> " . ($booking->guest_email ?: 'не указан'),
            "🌐 <b>Канал:</b> " . ($booking->channel ?: 'прямое'),
            "📅 <b>Заезд:</b> {$this->formatDate($booking->arrival_date)}",
            "📅 <b>Выезд:</b> {$this->formatDate($booking->departure_date)}",
            "🌙 <b>Ночей:</b> {$booking->nights}",
            "👥 <b>Гостей:</b> {$booking->num_adults} взрослых" . ($booking->num_children > 0 ? ", {$booking->num_children} детей" : ''),
            "🛏️ <b>Комната:</b> " . ($booking->room_name ?: 'не указана'),
            "💰 <b>Сумма:</b> {$booking->total_amount} {$booking->currency}",
            "💳 <b>Статус оплаты:</b> " . $this->translatePaymentStatus($booking->payment_status),
            "",
            "⏰ " . now('Asia/Tashkent')->format('d.m.Y H:i'),
        ]);
    }

    private function buildCancellationMessage(Beds24Booking $booking): string
    {
        $today = now('Asia/Tashkent')->toDateString();
        $isAfterCheckin = $booking->arrival_date && $booking->arrival_date->toDateString() <= $today;

        $prefix = $isAfterCheckin
            ? "🔴 <b>КРИТИЧНО: Отмена ПОСЛЕ заезда!</b>"
            : "🔴 <b>Бронирование отменено</b>";

        return implode("\n", [
            $prefix,
            "",
            "🏨 <b>Объект:</b> {$booking->getPropertyName()}",
            "🆔 <b>Бронирование:</b> #{$booking->beds24_booking_id}",
            "👤 <b>Гость:</b> {$booking->guest_name}",
            "📞 <b>Телефон:</b> " . ($booking->guest_phone ?: 'не указан'),
            "🌐 <b>Канал:</b> " . ($booking->channel ?: 'прямое'),
            "📅 <b>Заезд:</b> {$this->formatDate($booking->arrival_date)}",
            "📅 <b>Выезд:</b> {$this->formatDate($booking->departure_date)}",
            "💰 <b>Сумма:</b> {$booking->total_amount} {$booking->currency}",
            "",
            "⏰ " . now('Asia/Tashkent')->format('d.m.Y H:i'),
        ]);
    }

    // -------------------------------------------------------------------------
    // Ops / system alerts
    // -------------------------------------------------------------------------

    /**
     * Send a plain-text operational alert to the owner chat.
     * Used for policy violations, system warnings, and monitoring alerts.
     */
    public function sendOpsAlert(string $text): void
    {
        $this->send($text);
    }

    /**
     * "Cashier acts → System records → Owner knows" — direct alert when
     * a cashier records a Beds24 booking payment via the Telegram bot.
     *
     * MUST be dispatched AFTER the recordPayment DB transaction commits.
     * Implementation is fail-soft: a notification failure must NEVER roll
     * back the payment record. Caller wraps in try/catch.
     */
    public function alertCashierBotPayment(\App\Models\CashTransaction $tx): void
    {
        if ($this->ownerChatId === 0) {
            Log::warning('OwnerAlertService: owner chat ID not configured (cashier bot payment)', [
                'tx_id' => $tx->id,
            ]);
            return;
        }

        $cashierName = optional(\App\Models\User::find($tx->created_by))->name ?? 'Кассир #' . (int) $tx->created_by;
        $drawerName  = optional(\App\Models\CashierShift::find($tx->cashier_shift_id))?->cashDrawer?->name
                      ?? 'неизвестно';

        $methodLabel = match ($tx->payment_method) {
            'cash'     => '💵 Наличные',
            'card'     => '💳 Карта',
            'transfer' => '🔄 Перевод',
            null, ''   => 'не указан',
            default    => $tx->payment_method,
        };

        $currency = is_object($tx->currency) ? $tx->currency->value : (string) $tx->currency;
        $amount   = number_format((float) $tx->amount, 0, '.', ' ');
        $when     = optional($tx->occurred_at)->format('d.m.Y H:i') ?? '—';
        $bookingId = (int) $tx->beds24_booking_id;
        $guest    = trim((string) $tx->guest_name) ?: '—';
        $room     = trim((string) $tx->room_number);

        $lines = [
            '💰 <b>Касса: новая оплата</b>',
            '',
            "👤 Кассир: {$cashierName}",
            "🏪 Касса: {$drawerName}",
            "🛏 Гость: {$guest}" . ($room !== '' ? "  (комн. {$room})" : ''),
            "🔖 Бронь: #{$bookingId}",
            "💱 Метод: {$methodLabel}",
            "💵 Сумма: {$amount} {$currency}",
            "⏰ Время: {$when}",
        ];

        if ($tx->is_override) {
            $tier = is_object($tx->override_tier) ? $tx->override_tier->value : (string) $tx->override_tier;
            $lines[] = "⚠️ Override: tier={$tier}" . ($tx->override_reason ? " — {$tx->override_reason}" : '');
        }

        if ($tx->is_group_payment) {
            $lines[] = "👥 Групповая бронь (master #{$tx->group_master_booking_id})";
        }

        $this->send(implode("\n", $lines));
    }

    // -------------------------------------------------------------------------
    // Telegram HTTP helper
    // -------------------------------------------------------------------------

    private function send(string $text): void
    {
        if ($this->ownerChatId === 0) {
            Log::warning('OwnerAlertService: owner chat ID not configured');
            return;
        }

        SendTelegramNotificationJob::dispatch(
            'owner-alert',
            'sendMessage',
            [
                'chat_id'    => $this->ownerChatId,
                'text'       => $text,
                'parse_mode' => 'HTML',
            ]
        );
    }

    /**
     * Owner-side approval request for a shift close that hit Manager tier.
     *
     * Inline keyboard callbacks `approve_shift_<id>` / `reject_shift_<id>`
     * mirror the existing expense-approval pattern. C1.3 will wire
     * OwnerBotController to handle these callbacks.
     */
    public function requestShiftCloseApproval(
        CashierShift $shift,
        \App\DTOs\Cashier\ShiftCloseEvaluation $eval,
    ): void {
        if ($this->ownerChatId === 0) {
            Log::warning('OwnerAlertService: owner chat ID not configured (shift approval)', [
                'shift_id' => $shift->id,
            ]);
            return;
        }

        $text = $this->buildShiftApprovalMessage($shift, $eval);
        $keyboard = [
            'inline_keyboard' => [[
                ['text' => '✅ Одобрить', 'callback_data' => "approve_shift_{$shift->id}"],
                ['text' => '❌ Отклонить', 'callback_data' => "reject_shift_{$shift->id}"],
            ]],
        ];

        SendTelegramNotificationJob::dispatch(
            'owner-alert',
            'sendMessage',
            [
                'chat_id'      => $this->ownerChatId,
                'text'         => $text,
                'parse_mode'   => 'HTML',
                'reply_markup' => json_encode($keyboard),
            ]
        );

        Log::info('Shift close approval requested', [
            'shift_id'     => $shift->id,
            'tier'         => $eval->tier->value,
            'severity_uzs' => $eval->severityUzs,
            'fx_stale'     => $eval->fxStale,
        ]);
    }

    private function buildShiftApprovalMessage(
        CashierShift $shift,
        \App\DTOs\Cashier\ShiftCloseEvaluation $eval,
    ): string {
        $cashierName = optional($shift->user)->name ?? "user #{$shift->user_id}";
        $severity    = number_format($eval->severityUzs, 0, '.', ' ');

        $lines = [
            '🔍 <b>Закрытие смены требует одобрения</b>',
            '',
            "Кассир: <b>{$cashierName}</b>",
            "Смена: #{$shift->id} (открыта " . ($shift->opened_at?->format('Y-m-d H:i') ?? '—') . ')',
            "Расхождение (UZS-эквивалент): <b>{$severity} UZS</b>",
            '',
            '<b>По валютам:</b>',
        ];

        foreach ($eval->perCurrencyBreakdown as $currency => $row) {
            $delta = (float) ($row['delta'] ?? 0);
            if ($delta == 0.0) continue;
            $sign     = $delta > 0 ? '+' : '';
            $deltaStr = number_format($delta, 2, '.', ' ');
            $uzsEq    = number_format((float) ($row['uzs_equiv'] ?? 0), 0, '.', ' ');
            $lines[]  = "  {$currency}: {$sign}{$deltaStr} (~{$uzsEq} UZS)";
        }

        if ($eval->fxStale) {
            $lines[] = '';
            $lines[] = '⚠️ <i>Курсы ФX устарели — тир увеличен до Manager.</i>';
        }

        return implode("\n", $lines);
    }

    // -------------------------------------------------------------------------
    // Formatting helpers
    // -------------------------------------------------------------------------

    private function formatDate($date): string
    {
        if (!$date) {
            return 'не указана';
        }

        if ($date instanceof \Carbon\Carbon || $date instanceof \Illuminate\Support\Carbon) {
            return $date->locale('ru')->isoFormat('D MMMM YYYY (ddd)');
        }

        return Carbon::parse($date)->locale('ru')->isoFormat('D MMMM YYYY (ddd)');
    }

    private function translatePaymentStatus(string $status): string
    {
        return match ($status) {
            'paid'    => '✅ Оплачено',
            'partial' => '⚠️ Частично',
            'pending' => '🕐 Ожидает оплаты',
            default   => $status,
        };
    }

    // -------------------------------------------------------------------------
    // Cash Flow Reports
    // -------------------------------------------------------------------------

    public function sendDailyCashReport(array $data): void
    {
        $date = $data['date'];

        $text = implode("\n", array_filter([
            "💰 <b>Кассовый отчёт — {$date}</b>",
            "",
            "📥 <b>ПРИХОД (Доход):</b>",
            $this->formatIncomeSection($data['income']),
            "",
            "📤 <b>РАСХОД:</b>",
            $this->formatExpenseSection($data['expenses']),
            "",
            "━━━━━━━━━━━━━━━━━━",
            "📊 <b>ИТОГО за день:</b>",
            $this->formatBalanceSection($data['balance']),
            "",
            $data['shift_info'] ? "👤 <b>Смена:</b> {$data['shift_info']}" : null,
            "",
            "⏰ Сформировано: " . now('Asia/Tashkent')->format('d.m.Y H:i'),
        ]));

        $this->send($text);
    }

    public function sendMonthlyCashReport(array $data): void
    {
        $text = implode("\n", array_filter([
            "📊 <b>МЕСЯЧНЫЙ ОТЧЁТ — {$data['period']}</b>",
            "",
            "📥 <b>ПРИХОД (Доход):</b>",
            $this->formatIncomeSection($data['income']),
            "",
            "📤 <b>РАСХОД:</b>",
            $this->formatExpenseSection($data['expenses']),
            "",
            "━━━━━━━━━━━━━━━━━━",
            "📊 <b>ИТОГО за месяц:</b>",
            $this->formatBalanceSection($data['balance']),
            "",
            "👥 <b>Смены за месяц:</b> {$data['shifts_count']}",
            $data['prev_month_comparison'] ? "\n📈 <b>Сравнение с прошлым месяцем:</b>\n{$data['prev_month_comparison']}" : null,
            "",
            "⏰ Сформировано: " . now('Asia/Tashkent')->format('d.m.Y H:i'),
        ]));

        $this->send($text);
    }

    private function formatIncomeSection(array $income): string
    {
        $lines = [];
        foreach ($income as $currency => $details) {
            $total = number_format($details['total'], 2);
            $lines[] = "  <b>{$currency}:</b> {$total}";
            if (!empty($details['by_method'])) {
                foreach ($details['by_method'] as $method => $amount) {
                    $methodLabel = $this->translatePaymentMethod($method);
                    $lines[] = "    • {$methodLabel}: " . number_format($amount, 2);
                }
            }
        }
        return $lines ? implode("\n", $lines) : "  — нет";
    }

    private function formatExpenseSection(array $expenses): string
    {
        $lines = [];
        foreach ($expenses as $currency => $details) {
            $total = number_format($details['total'], 2);
            $lines[] = "  <b>{$currency}:</b> {$total}";
            if (!empty($details['items'])) {
                foreach ($details['items'] as $item) {
                    $lines[] = "    • {$item['notes']}: " . number_format($item['amount'], 2);
                }
            }
        }
        return $lines ? implode("\n", $lines) : "  — нет";
    }

    private function formatBalanceSection(array $balance): string
    {
        $lines = [];
        foreach ($balance as $currency => $amounts) {
            $in = number_format($amounts['in'], 2);
            $out = number_format($amounts['out'], 2);
            $net = number_format($amounts['net'], 2);
            $sign = $amounts['net'] >= 0 ? '+' : '';
            $lines[] = "  <b>{$currency}:</b> +{$in} / -{$out} = {$sign}{$net}";
        }
        return $lines ? implode("\n", $lines) : "  — нет данных";
    }

    private function translatePaymentMethod(string $method): string
    {
        return match (strtolower(trim($method))) {
            'naqd', 'cash', 'наличные' => '💵 Наличные',
            'plastk', 'card', 'карта'  => '💳 Карта',
            'perevod', 'transfer', 'перевод' => '🔄 Перевод',
            'karta' => '💳 Карта',
            default => $method ?: 'Не указан',
        };
    }

    // -------------------------------------------------------------------------
    // Reconciliation Alerts
    // -------------------------------------------------------------------------

    public function sendReconciliationAlert(array $results, string $date): void
    {
        $lines = [
            "\xF0\x9F\x94\xB4 <b>СВЕРКА ПЛАТЕЖЕЙ — {$date}</b>",
            "",
            "\xE2\x9A\xA0\xEF\xB8\x8F <b>Обнаружены расхождения!</b>",
            "",
        ];

        foreach ($results['flagged'] as $flag) {
            $emoji = $flag['status'] === 'no_payment' ? "\xF0\x9F\x9A\xAB" : "\xE2\x9A\xA0\xEF\xB8\x8F";
            $statusLabel = match($flag['status']) {
                'underpaid'  => 'Недоплата',
                'no_payment' => 'НЕТ ОПЛАТЫ',
                'overpaid'   => 'Переплата',
                default      => $flag['status'],
            };

            $lines[] = "{$emoji} <b>Бронь #{$flag['booking_id']}</b>";
            $lines[] = "  \xF0\x9F\x8F\xA8 {$flag['property']}";
            if (!empty($flag['room']) && $flag['room'] !== '—') $lines[] = "  \xF0\x9F\x9B\x8F\xEF\xB8\x8F Комната: {$flag['room']}";
            if (!empty($flag['guest']) && $flag['guest'] !== 'Не указан') $lines[] = "  \xF0\x9F\x91\xA4 {$flag['guest']}";
            if (!empty($flag['dates'])) $lines[] = "  \xF0\x9F\x93\x85 {$flag['dates']}";
            $lines[] = "  \xF0\x9F\x92\xB0 Ожидалось: {$flag['expected']} {$flag['currency']}";
            $lines[] = "  \xF0\x9F\x92\xB3 Получено: {$flag['reported']} {$flag['currency']}";
            $lines[] = "  \xE2\x9D\x8C <b>{$statusLabel}: {$flag['discrepancy']} {$flag['currency']}</b>";
            $lines[] = "";
        }

        $lines[] = "\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80\xE2\x94\x80";
        $lines[] = "\xF0\x9F\x93\x8A <b>Итого:</b> {$results['total']} бронирований";
        $lines[] = "  \xE2\x9C\x85 Совпадает: {$results['matched']}";
        $lines[] = "  \xE2\x9A\xA0\xEF\xB8\x8F Недоплата: {$results['underpaid']}";
        $lines[] = "  \xF0\x9F\x9A\xAB Нет оплаты: {$results['no_payment']}";
        $lines[] = "";
        $lines[] = "\xE2\x8F\xB0 " . now('Asia/Tashkent')->format('d.m.Y H:i');

        $this->send(implode("\n", $lines));
    }

    public function sendReconciliationSummary(array $results, string $date): void
    {
        $text = implode("\n", [
            "\xE2\x9C\x85 <b>Сверка платежей — {$date}</b>",
            "",
            "\xF0\x9F\x93\x8A Проверено: {$results['total']} бронирований",
            "  \xE2\x9C\x85 Совпадает: {$results['matched']}",
            "  \xE2\x9A\xA0\xEF\xB8\x8F Недоплата: {$results['underpaid']}",
            "  \xF0\x9F\x9A\xAB Нет оплаты: {$results['no_payment']}",
            "",
            "Расхождений не обнаружено \xE2\x9C\x85",
            "",
            "\xE2\x8F\xB0 " . now('Asia/Tashkent')->format('d.m.Y H:i'),
        ]);

        $this->send($text);
    }

    /**
     * Send shift close report to owner (with HTML formatting)
     */
    public function sendShiftCloseReport(string $htmlMessage): void
    {
        $this->send($htmlMessage);
    }
}
