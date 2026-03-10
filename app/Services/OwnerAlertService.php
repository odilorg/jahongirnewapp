<?php

namespace App\Services;

use App\Models\Beds24Booking;
use App\Models\Beds24BookingChange;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class OwnerAlertService
{
    protected string $botToken;
    protected int $ownerChatId;
    protected string $apiBase;

    public function __construct()
    {
        // Use the dedicated alert bot token (passed in task spec)
        $this->botToken   = config('services.owner_alert_bot.token', env('OWNER_ALERT_BOT_TOKEN', '8404071021:AAF3uET88mdd-PxNsmOnUkdgETA1nJiM5_4'));
        $this->ownerChatId = (int) config('services.owner_alert_bot.owner_chat_id', env('OWNER_TELEGRAM_ID', '38738713'));
        $this->apiBase    = "https://api.telegram.org/bot{$this->botToken}";
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
        try {
            $response = Http::timeout(10)->post("{$this->apiBase}/sendMessage", [
                'chat_id'    => $this->ownerChatId,
                'text'       => $text,
                'parse_mode' => 'HTML',
            ]);

            if (!$response->successful()) {
                Log::warning('OwnerAlertService: Failed to send Telegram message', [
                    'status'   => $response->status(),
                    'response' => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            // Never let a Telegram failure break webhook processing
            Log::error('OwnerAlertService: Exception sending Telegram message', [
                'error' => $e->getMessage(),
            ]);
        }
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
}
