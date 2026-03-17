<?php

namespace App\Services;

use App\Models\GygInboundEmail;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Send Telegram notifications to the owner for GYG booking events.
 *
 * Notification is idempotent: skips if notified_at is already set.
 * Sets notified_at only on successful send.
 * If send fails, notified_at stays null — next run will retry.
 *
 * Separation of concerns: notification failure NEVER corrupts booking state.
 */
class GygNotifier
{
    private const TG_API_URL = 'http://127.0.0.1:8766/send';
    private const OWNER_CHAT_ID = '38738713';

    /**
     * Notify owner about a GYG event if not already notified.
     */
    public function notifyIfNeeded(GygInboundEmail $email, string $event, array $extra = []): bool
    {
        // Idempotency: skip if already notified
        if ($email->notified_at !== null) {
            return true;
        }

        $message = $this->buildMessage($email, $event, $extra);

        try {
            $sent = $this->sendTelegram($message);

            if ($sent) {
                $email->update(['notified_at' => now()]);
                return true;
            }

            Log::warning('GygNotifier: Telegram send returned false', [
                'email_id' => $email->id,
                'event'    => $event,
            ]);
            return false;
        } catch (\Throwable $e) {
            // Notification failure must NOT corrupt booking state
            Log::error('GygNotifier: Telegram send failed', [
                'email_id' => $email->id,
                'event'    => $event,
                'error'    => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function buildMessage(GygInboundEmail $email, string $event, array $extra): string
    {
        $icon = match ($event) {
            'new_booking'   => '🆕',
            'cancellation'  => '❌',
            'amendment'     => '✏️',
            'needs_review'  => '⚠️',
            'apply_failure' => '🔴',
            default         => '📧',
        };

        $title = match ($event) {
            'new_booking'   => 'GYG: New Booking Applied',
            'cancellation'  => 'GYG: Booking Cancelled',
            'amendment'     => 'GYG: Booking Amendment',
            'needs_review'  => 'GYG: Needs Review',
            'apply_failure' => 'GYG: Apply Failed',
            default         => 'GYG: Event',
        };

        $lines = ["{$icon} {$title}"];

        if ($email->gyg_booking_reference) {
            $lines[] = "Ref: {$email->gyg_booking_reference}";
        }
        if ($email->tour_name) {
            $lines[] = "Tour: {$email->tour_name}";
        }
        if ($email->option_title) {
            $lines[] = "Option: {$email->option_title}";
        }
        if ($email->guest_name) {
            $lines[] = "Guest: {$email->guest_name}";
        }
        if ($email->travel_date) {
            $date = $email->travel_date instanceof \DateTimeInterface
                ? $email->travel_date->format('M d, Y')
                : $email->travel_date;
            $lines[] = "Date: {$date}";
        }
        if ($email->pax) {
            $lines[] = "Pax: {$email->pax}";
        }

        // Extra context
        if (! empty($extra['booking_id'])) {
            $lines[] = "Booking #{$extra['booking_id']}";
        }
        if (! empty($extra['reason'])) {
            $lines[] = "Reason: {$extra['reason']}";
        }
        if (! empty($extra['error'])) {
            $lines[] = "Error: " . mb_substr($extra['error'], 0, 200);
        }

        return implode("\n", $lines);
    }

    private function sendTelegram(string $message): bool
    {
        // Try tg-api (personal Telegram) first
        try {
            $response = Http::timeout(10)->post(self::TG_API_URL, [
                'to'      => self::OWNER_CHAT_ID,
                'message' => $message,
            ]);

            if ($response->successful() && ($response->json('ok') ?? false)) {
                return true;
            }
        } catch (\Throwable $e) {
            Log::warning('GygNotifier: tg-api unavailable, trying bot API', ['error' => $e->getMessage()]);
        }

        // Fallback: direct Telegram Bot API
        $botToken = config('services.driver_guide_bot.token', '');
        if (empty($botToken)) {
            Log::error('GygNotifier: no Telegram bot token available');
            return false;
        }

        try {
            $response = Http::timeout(10)->post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                'chat_id' => self::OWNER_CHAT_ID,
                'text'    => $message,
            ]);

            return $response->successful() && ($response->json('ok') ?? false);
        } catch (\Throwable $e) {
            Log::error('GygNotifier: bot API also failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
}
