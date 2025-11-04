<?php

namespace App\Services;

use App\Models\GetYourGuideBooking;
use App\Services\TelegramBotService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class GetYourGuideNotificationService
{
    public function __construct(
        protected TelegramBotService $telegram
    ) {}

    /**
     * Send new booking notification to staff
     */
    public function notifyNewBooking(
        GetYourGuideBooking $booking,
        array $extractedData,
        int $processingTimeMs
    ): bool {
        if (!$this->isEnabled()) {
            Log::debug('GetYourGuide notifications disabled');
            return true;
        }

        $chatIds = $this->getStaffChatIds();
        if (empty($chatIds)) {
            Log::warning('No staff chat IDs configured for GetYourGuide notifications');
            return true;
        }

        $message = $this->formatBookingMessage($booking, $extractedData, $processingTimeMs);

        return $this->sendToStaff($message, $chatIds);
    }

    /**
     * Send error notification to staff
     */
    public function notifyProcessingError(
        string $emailSubject,
        string $emailFrom,
        string $errorMessage,
        ?array $context = null
    ): bool {
        if (!$this->isEnabled()) {
            return true;
        }

        $chatIds = $this->getStaffChatIds();
        if (empty($chatIds)) {
            return true;
        }

        $message = $this->formatErrorMessage($emailSubject, $emailFrom, $errorMessage, $context);

        return $this->sendToStaff($message, $chatIds);
    }

    /**
     * Send daily summary notification
     */
    public function sendDailySummary(Carbon $date): bool
    {
        if (!$this->isEnabled()) {
            return true;
        }

        if (!config('getyourguide.notifications.daily_summary.enabled', false)) {
            return true;
        }

        $chatIds = $this->getStaffChatIds();
        if (empty($chatIds)) {
            return true;
        }

        $stats = $this->getDailyStats($date);
        $message = $this->formatSummaryMessage($stats, $date);

        return $this->sendToStaff($message, $chatIds);
    }

    /**
     * Format booking data into beautiful Telegram message
     */
    protected function formatBookingMessage(
        GetYourGuideBooking $booking,
        array $extractedData,
        int $processingTimeMs
    ): string {
        $sections = [];

        // Header
        $sections[] = "🎫 <b>NEW GETYOURGUIDE BOOKING</b>";
        $sections[] = "";

        // Booking Details
        $sections[] = "📋 <b>Booking Details</b>";
        $sections[] = "━━━━━━━━━━━━━━━━━━━━";
        $sections[] = "🔖 Reference: <code>{$booking->booking_reference}</code>";
        $sections[] = "📅 Tour Date: {$this->formatDate($booking->tour_date)}";

        if ($booking->tour_time) {
            $sections[] = "🕐 Time: {$this->formatTime($booking->tour_time)}";
        }

        $sections[] = "🎯 Tour: " . $this->truncate($booking->tour_name, 80);

        if ($booking->duration) {
            $sections[] = "⏱ Duration: {$booking->duration}";
        }

        // Guest Information
        $sections[] = "";
        $sections[] = "👤 <b>Guest Information</b>";
        $sections[] = "━━━━━━━━━━━━━━━━━━━━";
        $sections[] = "👨 Name: {$booking->guest_name}";

        if ($booking->guest_email) {
            $sections[] = "📧 Email: <code>" . $this->truncate($booking->guest_email, 50) . "</code>";
        }

        if ($booking->guest_phone) {
            $sections[] = "📱 Phone: {$this->formatPhone($booking->guest_phone)}";
        }

        if ($booking->language) {
            $sections[] = "🌍 Language: {$booking->language}";
        }

        $guestCount = [];
        if ($booking->adults > 0) {
            $guestCount[] = "{$booking->adults} Adult" . ($booking->adults > 1 ? 's' : '');
        }
        if ($booking->children > 0) {
            $guestCount[] = "{$booking->children} Child" . ($booking->children > 1 ? 'ren' : '');
        }
        if (!empty($guestCount)) {
            $sections[] = "👥 Guests: " . implode(', ', $guestCount);
        }

        // Pickup Details
        if ($booking->pickup_location) {
            $sections[] = "";
            $sections[] = "📍 <b>Pickup Details</b>";
            $sections[] = "━━━━━━━━━━━━━━━━━━━━";
            $sections[] = "📍 Location: " . $this->truncate($booking->pickup_location, 100);

            if ($booking->pickup_time) {
                $sections[] = "🕐 Pickup Time: {$this->formatTime($booking->pickup_time)}";
            }
        }

        // Special Requirements
        if ($booking->special_requirements) {
            $sections[] = "";
            $sections[] = "📝 <b>Special Requirements</b>";
            $sections[] = "━━━━━━━━━━━━━━━━━━━━";
            $sections[] = $this->truncate($booking->special_requirements, 150);
        }

        // Payment
        $sections[] = "";
        $sections[] = "💰 <b>Payment</b>";
        $sections[] = "━━━━━━━━━━━━━━━━━━━━";
        $sections[] = "💵 Amount: {$this->formatPrice($booking->total_price, $booking->currency)}";

        if ($booking->payment_status) {
            $emoji = $this->getPaymentStatusEmoji($booking->payment_status);
            $status = strtoupper($booking->payment_status);
            $sections[] = "{$emoji} Status: <b>{$status}</b>";
        }

        // Footer
        $processingTime = number_format($processingTimeMs / 1000, 1);
        $sections[] = "";
        $sections[] = "⏱ <i>Processed in {$processingTime}s</i>";

        return implode("\n", $sections);
    }

    /**
     * Format error message
     */
    protected function formatErrorMessage(
        string $emailSubject,
        string $emailFrom,
        string $errorMessage,
        ?array $context = null
    ): string {
        $sections = [];

        $sections[] = "⚠️ <b>GETYOURGUIDE PROCESSING ERROR</b>";
        $sections[] = "";
        $sections[] = "📧 Email: <code>{$emailFrom}</code>";
        $sections[] = "📨 Subject: " . $this->truncate($emailSubject, 80);
        $sections[] = "❌ Error: {$errorMessage}";

        if ($context && !empty($context)) {
            $sections[] = "";
            $sections[] = "<b>Details:</b>";
            foreach ($context as $key => $value) {
                if (is_string($value) || is_numeric($value)) {
                    $sections[] = "• {$key}: " . $this->truncate((string)$value, 100);
                }
            }
        }

        $sections[] = "";
        $sections[] = "<i>Please check manually.</i>";

        return implode("\n", $sections);
    }

    /**
     * Format daily summary message
     */
    protected function formatSummaryMessage(array $stats, Carbon $date): string
    {
        $sections = [];

        $sections[] = "📊 <b>GETYOURGUIDE DAILY SUMMARY</b>";
        $sections[] = "";
        $sections[] = "📅 Date: {$this->formatDate($date)}";
        $sections[] = "";

        $sections[] = "✅ Successfully Processed: <b>{$stats['successful']}</b> bookings";
        $sections[] = "❌ Failed: <b>{$stats['failed']}</b> bookings";

        if ($stats['revenue'] > 0) {
            $sections[] = "💰 Total Revenue: <b>{$this->formatPrice($stats['revenue'], 'USD')}</b>";
        }

        if (!empty($stats['top_tours'])) {
            $sections[] = "";
            $sections[] = "<b>Top Tours:</b>";
            foreach ($stats['top_tours'] as $index => $tour) {
                $num = $index + 1;
                $sections[] = "{$num}. {$tour['name']} ({$tour['count']} bookings)";
            }
        }

        return implode("\n", $sections);
    }

    /**
     * Send message to all configured staff chat IDs
     */
    protected function sendToStaff(string $message, array $chatIds): bool
    {
        $allSuccess = true;

        foreach ($chatIds as $chatId) {
            try {
                $this->telegram->sendMessage(
                    chatId: (int) $chatId,
                    text: $message,
                    options: [
                        'parse_mode' => 'HTML',
                        'disable_web_page_preview' => true,
                    ]
                );

                Log::info('GetYourGuide notification sent', [
                    'chat_id' => $chatId,
                ]);

            } catch (\Exception $e) {
                Log::error('GetYourGuide notification failed', [
                    'chat_id' => $chatId,
                    'error' => $e->getMessage(),
                ]);
                $allSuccess = false;
            }
        }

        return $allSuccess;
    }

    /**
     * Get daily statistics
     */
    protected function getDailyStats(Carbon $date): array
    {
        $startOfDay = $date->copy()->startOfDay();
        $endOfDay = $date->copy()->endOfDay();

        $successful = GetYourGuideBooking::whereBetween('created_at', [$startOfDay, $endOfDay])
            ->where('processing_status', 'completed')
            ->count();

        $failed = GetYourGuideBooking::whereBetween('created_at', [$startOfDay, $endOfDay])
            ->where('processing_status', 'failed')
            ->count();

        $revenue = GetYourGuideBooking::whereBetween('created_at', [$startOfDay, $endOfDay])
            ->where('processing_status', 'completed')
            ->sum('total_price');

        $topTours = GetYourGuideBooking::whereBetween('created_at', [$startOfDay, $endOfDay])
            ->where('processing_status', 'completed')
            ->selectRaw('tour_name, COUNT(*) as count')
            ->groupBy('tour_name')
            ->orderByDesc('count')
            ->limit(5)
            ->get()
            ->map(fn($item) => [
                'name' => $this->truncate($item->tour_name, 50),
                'count' => $item->count,
            ])
            ->toArray();

        return [
            'successful' => $successful,
            'failed' => $failed,
            'revenue' => $revenue,
            'top_tours' => $topTours,
        ];
    }

    /**
     * Check if notifications are enabled
     */
    protected function isEnabled(): bool
    {
        return config('getyourguide.notifications.enabled', true);
    }

    /**
     * Get staff chat IDs from configuration
     */
    protected function getStaffChatIds(): array
    {
        $chatIds = config('getyourguide.notifications.staff_chat_ids', []);

        // Filter to only numeric values
        return array_filter($chatIds, fn($id) => is_numeric($id));
    }

    /**
     * Format date for display
     */
    protected function formatDate($date, string $format = 'F j, Y'): string
    {
        if (!$date) {
            return 'Not specified';
        }

        if (is_string($date)) {
            $date = Carbon::parse($date);
        }

        return $date->format($format);
    }

    /**
     * Format time for display
     */
    protected function formatTime(?string $time): string
    {
        if (!$time) {
            return 'Not specified';
        }

        try {
            return Carbon::parse($time)->format('g:i A');
        } catch (\Exception $e) {
            return $time;
        }
    }

    /**
     * Format price for display
     */
    protected function formatPrice(float $amount, string $currency): string
    {
        $symbols = [
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'JPY' => '¥',
            'RUB' => '₽',
        ];

        $symbol = $symbols[$currency] ?? $currency;
        $formatted = number_format($amount, 2);

        return "{$symbol}{$formatted}";
    }

    /**
     * Format phone number for display
     */
    protected function formatPhone(?string $phone): string
    {
        if (!$phone) {
            return 'Not provided';
        }

        // Already in international format: +33783356396
        // Add spaces for readability: +33 7 83 35 63 96
        if (preg_match('/^\+(\d{2})(\d{1})(\d{2})(\d{2})(\d{2})(\d{2})$/', $phone, $matches)) {
            return "+{$matches[1]} {$matches[2]} {$matches[3]} {$matches[4]} {$matches[5]} {$matches[6]}";
        }

        return $phone;
    }

    /**
     * Truncate long text for display
     */
    protected function truncate(?string $text, int $maxLength = 100): string
    {
        if (!$text) {
            return '';
        }

        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength - 3) . '...';
    }

    /**
     * Get emoji for payment status
     */
    protected function getPaymentStatusEmoji(?string $status): string
    {
        return match (strtolower($status ?? '')) {
            'paid' => '✅',
            'pending' => '⏳',
            'refunded' => '↩️',
            'cancelled' => '❌',
            default => '❓',
        };
    }
}
