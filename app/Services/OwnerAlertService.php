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
