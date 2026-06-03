<?php

declare(strict_types=1);

namespace App\Services;

use App\Console\Commands\TourSendReminders;
use App\Models\BookingInquiry;
use App\Services\Messaging\WhatsAppSender;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Dispatcher for per-inquiry guest tour reminders.
 *
 * Why this exists
 *   The daily `tour:send-reminders` cron (20:00 Tashkent) only catches
 *   bookings that exist *at* 20:00. Late bookings (post-20:00 for
 *   tomorrow's tour, or same-day) miss the batch. This service is the
 *   shared engine for all three reminder paths:
 *
 *     1. Daily batch  (TourSendReminders, 20:00 Tashkent — Phase 2 loop)
 *     2. Hourly catch-up  (TourSendLateGuestReminders, every hour)
 *     3. On-confirm fast path  (SendTourReminderJob, dispatched
 *        afterCommit by ConfirmBookingAction when travel is within 24h)
 *
 * Idempotency contract (Phase 27 — robust anti-duplicate)
 *   - `guest_reminder_sent_at` is the primary audit field for "sent".
 *   - `guest_reminder_status` tracks the state machine:
 *       null → sending → sent | unknown | failed → suppressed
 *   - The guard acquires a row-level lock (lockForUpdate) inside a
 *     transaction before inspecting state. This serialises concurrent
 *     send attempts — whichever wins the lock stamps "sending" first;
 *     the loser sees the stamp and no-ops.
 *   - An idempotency key (deterministic, not random) is stamped before
 *     the HTTP call so a PHP crash mid-HTTP does not lose the fact that
 *     a send was attempted.
 *   - Timeout / connection errors mark status "unknown" (not "failed").
 *     "unknown" blocks automatic retry for 4 hours. After 2 unknown/failed
 *     attempts the system suppresses further automatic sends and logs a
 *     manual-review alert.
 *   - The system is fail-closed: if we cannot confirm delivery, we stop
 *     rather than risk spamming a guest.
 *
 * Window contract
 *   - departureAt() = travel_date + pickup_time (default 09:00 Tashkent).
 *   - Reminder fires when departureAt is within [now, now+24h]
 *     and inquiry.status === confirmed.
 */
class TourReminderDispatcher
{
    public function __construct(
        private readonly WhatsAppSender $whatsApp,
    ) {}

    /**
     * Compute the inquiry's departure datetime in Tashkent.
     * Returns null if travel_date is missing — caller skips.
     */
    public function departureAt(BookingInquiry $inquiry): ?Carbon
    {
        if ($inquiry->travel_date === null) {
            return null;
        }
        $date = $inquiry->travel_date->format('Y-m-d');
        $time = $inquiry->pickup_time
            ? substr((string) $inquiry->pickup_time, 0, 8)
            : '09:00:00';
        try {
            return Carbon::parse("{$date} {$time}", 'Asia/Tashkent');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Send the WhatsApp guest reminder for one inquiry.
     *
     * This is the SINGLE entry point for all three reminder paths
     * (daily batch, hourly catch-up, on-confirm fast path). Every
     * path must go through this guard — no caller is allowed to
     * contact WhatsAppSender directly for guest reminders.
     *
     * Returns:
     *   ['ok' => true,  'msg_id' => …, 'lead_time_minutes' => N]
     *   ['ok' => false, 'reason' => 'already_sent'|'currently_sending'|'throttled'|'suppressed'|'not_confirmed'|'out_of_window'|'no_phone'|'no_departure_at'|'wa_failed'|'wa_timeout'|…]
     *
     * Never throws — failures are returned + logged so callers can
     * branch on outcome.
     */
    public function sendGuestReminder(BookingInquiry $inquiry, string $source): array
    {
        // ── Fast-reject: sent_at (the primary audit field) ──────────────
        if ($inquiry->guest_reminder_sent_at !== null) {
            Log::info('TourReminderDispatcher: skipped — already sent (sent_at)', [
                'inquiry_id' => $inquiry->id,
                'reference' => $inquiry->reference,
                'source' => $source,
            ]);

            return ['ok' => false, 'reason' => 'already_sent'];
        }

        // ── Fast-reject: status guard ───────────────────────────────────
        if ($inquiry->guest_reminder_status === BookingInquiry::REMINDER_STATUS_SENT) {
            Log::info('TourReminderDispatcher: skipped — already sent (status=sent)', [
                'inquiry_id' => $inquiry->id,
                'reference' => $inquiry->reference,
                'source' => $source,
            ]);

            return ['ok' => false, 'reason' => 'already_sent'];
        }

        // ── Fast-reject: not confirmed ──────────────────────────────────
        if ($inquiry->status !== BookingInquiry::STATUS_CONFIRMED) {
            return ['ok' => false, 'reason' => 'not_confirmed'];
        }

        // ── Fast-reject: outside 24h window ─────────────────────────────
        $departure = $this->departureAt($inquiry);
        if ($departure === null) {
            return ['ok' => false, 'reason' => 'no_departure_at'];
        }

        $now = Carbon::now('Asia/Tashkent');
        $minutesUntil = $now->diffInMinutes($departure, false);
        if ($minutesUntil < 0 || $minutesUntil > 24 * 60) {
            return ['ok' => false, 'reason' => 'out_of_window'];
        }

        // ── Fast-reject: no valid phone ─────────────────────────────────
        $phone = $this->whatsApp->normalizePhone($inquiry->customer_phone);
        if (! $phone) {
            return ['ok' => false, 'reason' => 'no_phone'];
        }

        // ═══════════════════════════════════════════════════════════════
        // Row-locked guard — serialises concurrent attempts
        // ═══════════════════════════════════════════════════════════════

        $guardResult = DB::transaction(function () use ($inquiry, $source, $now) {
            // Lock the row for update — any concurrent dispatcher for
            // the same inquiry blocks here until we commit/rollback.
            $fresh = BookingInquiry::query()
                ->where('id', $inquiry->id)
                ->lockForUpdate()
                ->first();

            if (! $fresh) {
                return ['ok' => false, 'reason' => 'not_found'];
            }

            // Re-read state from the locked row (the in-memory $inquiry
            // may be stale if a concurrent transaction stamped first).
            $sentAt = $fresh->guest_reminder_sent_at;
            $status = $fresh->guest_reminder_status;
            $lastAttempted = $fresh->guest_reminder_last_attempted_at;
            $attemptCount = (int) $fresh->guest_reminder_attempt_count;

            // ── Guard: already sent (re-check under lock) ────────────
            if ($sentAt !== null || $status === BookingInquiry::REMINDER_STATUS_SENT) {
                Log::info('TourReminderDispatcher: skipped under lock — already sent', [
                    'inquiry_id' => $fresh->id,
                    'reference' => $fresh->reference,
                    'source' => $source,
                    'current_status' => $status,
                ]);

                return ['ok' => false, 'reason' => 'already_sent'];
            }

            // ── Guard: currently sending (stale-sending detection) ──
            if (
                $status === BookingInquiry::REMINDER_STATUS_SENDING
                && $lastAttempted !== null
                && $lastAttempted->diffInMinutes($now, false) < BookingInquiry::REMINDER_SENDING_STALE_MINUTES
            ) {
                Log::info('TourReminderDispatcher: skipped — currently sending', [
                    'inquiry_id' => $fresh->id,
                    'reference' => $fresh->reference,
                    'source' => $source,
                    'last_attempted' => $lastAttempted->toIso8601String(),
                    'minutes_ago' => $lastAttempted->diffInMinutes($now, false),
                ]);

                return ['ok' => false, 'reason' => 'currently_sending'];
            }

            // ── Guard: throttle after recent attempt ─────────────────
            if (
                $lastAttempted !== null
                && $lastAttempted->diffInMinutes($now, false) < BookingInquiry::REMINDER_THROTTLE_MINUTES
            ) {
                Log::info('TourReminderDispatcher: skipped — throttled after recent attempt', [
                    'inquiry_id' => $fresh->id,
                    'reference' => $fresh->reference,
                    'source' => $source,
                    'last_attempted' => $lastAttempted->toIso8601String(),
                    'minutes_ago' => $lastAttempted->diffInMinutes($now, false),
                ]);

                return ['ok' => false, 'reason' => 'throttled'];
            }

            // ── Guard: max attempts → suppress ───────────────────────
            if (
                $attemptCount >= BookingInquiry::REMINDER_MAX_ATTEMPTS
                && in_array($status, [
                    BookingInquiry::REMINDER_STATUS_FAILED,
                    BookingInquiry::REMINDER_STATUS_UNKNOWN,
                ], true)
            ) {
                $fresh->forceFill([
                    'guest_reminder_status' => BookingInquiry::REMINDER_STATUS_SUPPRESSED,
                    'guest_reminder_last_error' => 'suppressed after '.$attemptCount.' attempt(s) — manual review required',
                    'guest_reminder_last_attempted_at' => $now,
                ])->save();

                Log::warning('TourReminderDispatcher: SUPPRESSED — max attempts reached, MANUAL REVIEW REQUIRED', [
                    'inquiry_id' => $fresh->id,
                    'reference' => $fresh->reference,
                    'source' => $source,
                    'attempt_count' => $attemptCount,
                    'last_status' => $status,
                    'guest_name' => $fresh->customer_name,
                    'customer_phone' => $fresh->customer_phone,
                ]);

                return ['ok' => false, 'reason' => 'suppressed'];
            }

            // ── Mark "sending" BEFORE the HTTP call ──────────────────
            $idempotencyKey = $fresh->reminderIdempotencyKey();

            $fresh->forceFill([
                'guest_reminder_status' => BookingInquiry::REMINDER_STATUS_SENDING,
                'guest_reminder_last_attempted_at' => $now,
                'guest_reminder_attempt_count' => $attemptCount + 1,
                'guest_reminder_idempotency_key' => $idempotencyKey,
                'guest_reminder_last_error' => null,
            ])->save();

            Log::info('TourReminderDispatcher: marked sending', [
                'inquiry_id' => $fresh->id,
                'reference' => $fresh->reference,
                'source' => $source,
                'attempt_number' => $attemptCount + 1,
                'idempotency_key' => $idempotencyKey,
            ]);

            // Return the phone and message data needed for the HTTP call
            // (outside the transaction, so we don't hold the lock).
            return [
                'ok' => true,
                'reason' => 'proceed',
                'phone' => $phone,
                'minutes_until' => $minutesUntil,
            ];
        });

        // If the guard blocked the send, return the reason immediately.
        if (! ($guardResult['ok'] ?? false)) {
            return $guardResult;
        }

        // ═══════════════════════════════════════════════════════════════
        // HTTP call — OUTSIDE the transaction
        // ═══════════════════════════════════════════════════════════════

        // Build the rich guest message (packing list, weather, pickup,
        // contact block) by delegating to the Console command's builder.
        $command = app(TourSendReminders::class);
        $message = $this->buildMessageViaCommand($command, $inquiry, $departure);

        $result = $this->whatsApp->send($phone, $message);

        // Always log to tour_reminder_logs for audit trail.
        DB::table('tour_reminder_logs')->insert([
            'booking_inquiry_id' => $inquiry->id,
            'channel' => 'whatsapp',
            'phone' => $phone,
            'status' => $result->success ? 'sent' : 'failed',
            'error_message' => $result->success ? null : $result->error,
            'scheduled_for_date' => $inquiry->travel_date?->format('Y-m-d'),
            'reminded_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // ═══════════════════════════════════════════════════════════════
        // Post-send state update (no lock needed — sent_at/stamp wins)
        // ═══════════════════════════════════════════════════════════════

        if ($result->success) {
            // ── Confirmed success ────────────────────────────────────
            $inquiry->forceFill([
                'guest_reminder_status' => BookingInquiry::REMINDER_STATUS_SENT,
                'guest_reminder_sent_at' => now(),
                'guest_reminder_last_error' => null,
            ])->save();

            Log::info('TourReminderDispatcher: guest reminder sent ✅', [
                'inquiry_id' => $inquiry->id,
                'reference' => $inquiry->reference,
                'source' => $source,
                'lead_time_minutes' => $minutesUntil,
                'attempt_number' => $guardResult['attempt_number'] ?? null,
            ]);

            return [
                'ok' => true,
                'msg_id' => null,
                'lead_time_minutes' => $minutesUntil,
            ];
        }

        // ── Failure: classify timeout vs clear failure ────────────────
        $errorMsg = $result->error ?? 'unknown error';
        $isTimeout = str_contains(strtolower($errorMsg), 'timeout')
            || str_contains(strtolower($errorMsg), 'timed out')
            || str_contains(strtolower($errorMsg), 'connection');

        if ($isTimeout) {
            // Timeout → "unknown": the wa-api may or may not have
            // delivered the message. We MUST NOT keep retrying.
            $inquiry->forceFill([
                'guest_reminder_status' => BookingInquiry::REMINDER_STATUS_UNKNOWN,
                'guest_reminder_last_error' => $errorMsg,
                // DO NOT clear last_attempted_at — it is the throttle anchor.
            ])->save();

            Log::warning('TourReminderDispatcher: WhatsApp timeout — status set to UNKNOWN, MANUAL REVIEW REQUIRED', [
                'inquiry_id' => $inquiry->id,
                'reference' => $inquiry->reference,
                'source' => $source,
                'error' => $errorMsg,
                'attempt_number' => $guardResult['attempt_number'] ?? null,
                'guest_name' => $inquiry->customer_name,
                'customer_phone' => $inquiry->customer_phone,
            ]);

            return ['ok' => false, 'reason' => 'wa_timeout', 'error' => $errorMsg];
        }

        // Clear failure — not a timeout, wa-api definitely rejected.
        $inquiry->forceFill([
            'guest_reminder_status' => BookingInquiry::REMINDER_STATUS_FAILED,
            'guest_reminder_last_error' => $errorMsg,
        ])->save();

        Log::warning('TourReminderDispatcher: WhatsApp send failed', [
            'inquiry_id' => $inquiry->id,
            'reference' => $inquiry->reference,
            'source' => $source,
            'error' => $errorMsg,
            'attempt_number' => $guardResult['attempt_number'] ?? null,
        ]);

        return ['ok' => false, 'reason' => 'wa_failed', 'error' => $errorMsg];
    }

    /**
     * Bridge to the existing message builder on TourSendReminders.
     * Reuses the rich rendering (pickup/contact/weather/meals/packing)
     * without duplicating ~150 lines of formatting code.
     */
    private function buildMessageViaCommand(
        TourSendReminders $command,
        BookingInquiry $inquiry,
        Carbon $departure,
    ): string {
        $dateLabel = $departure->format('D, d M Y');

        return $command->buildGuestMessagePublic($inquiry, $dateLabel);
    }
}
