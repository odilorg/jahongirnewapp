<?php

declare(strict_types=1);

namespace App\Services;

use App\Console\Commands\TourSendReminders;
use App\Mail\TourGuestReminderMail;
use App\Models\BookingInquiry;
use App\Services\Messaging\WhatsAppSender;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

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
 * Channels (Phase 28)
 *   - WhatsApp when the booking has a usable phone (default for direct /
 *     website / WA bookings).
 *   - Email fallback when there is no phone but a valid email — typical
 *     for OTA bookings (GYG / Viator) whose guest phone is never shared.
 *     Gated by config('tour_experience.email_fallback_enabled').
 *   - Neither → a one-shot operator Telegram alert, then the booking is
 *     marked SUPPRESSED so it does not re-alert every run.
 *   Channel selection happens ONCE, before the row lock; the booking-level
 *   idempotency guard below is channel-agnostic, so a booking receives at
 *   most one reminder total — never both WhatsApp and email.
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
 *     the send so a PHP crash mid-send does not lose the fact that a
 *     send was attempted.
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
        private readonly OwnerAlertService $ownerAlert,
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
     * Send the guest tour reminder for one inquiry (WhatsApp or email).
     *
     * This is the SINGLE entry point for all three reminder paths
     * (daily batch, hourly catch-up, on-confirm fast path). Every
     * path must go through this guard — no caller is allowed to
     * contact WhatsAppSender / Mail directly for guest reminders.
     *
     * Returns:
     *   ['ok' => true,  'channel' => 'whatsapp'|'email', 'lead_time_minutes' => N]
     *   ['ok' => false, 'reason' => 'already_sent'|'currently_sending'|'throttled'
     *                              |'suppressed'|'not_confirmed'|'out_of_window'
     *                              |'no_contact'|'no_departure_at'
     *                              |'wa_failed'|'wa_timeout'
     *                              |'email_failed'|'email_unknown'|…]
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

        // ── Channel resolution (pure read, BEFORE the lock) ─────────────
        // Phone wins; else valid email; else no_contact. Choosing the
        // channel before the transaction keeps the lock body channel-
        // agnostic and guarantees exactly one channel per booking.
        [$channel, $recipient] = $this->resolveChannel($inquiry);
        if ($channel === null) {
            // Neither phone nor email — alert the operator once, then
            // suppress so subsequent runs do not re-alert.
            return $this->handleNoContact($inquiry, $source, $now);
        }

        // ═══════════════════════════════════════════════════════════════
        // Row-locked guard — serialises concurrent attempts
        // ═══════════════════════════════════════════════════════════════

        $guardResult = DB::transaction(function () use ($inquiry, $source, $now, $channel, $recipient, $minutesUntil) {
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

            // ── Mark "sending" BEFORE the send ───────────────────────
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
                'channel' => $channel,
                'attempt_number' => $attemptCount + 1,
                'idempotency_key' => $idempotencyKey,
            ]);

            // Return the channel + recipient needed for the send
            // (performed outside the transaction so we don't hold the lock).
            return [
                'ok' => true,
                'reason' => 'proceed',
                'channel' => $channel,
                'recipient' => $recipient,
                'minutes_until' => $minutesUntil,
                'attempt_number' => $attemptCount + 1,
            ];
        });

        // If the guard blocked the send, return the reason immediately.
        if (! ($guardResult['ok'] ?? false)) {
            return $guardResult;
        }

        // ═══════════════════════════════════════════════════════════════
        // Send — OUTSIDE the transaction
        // ═══════════════════════════════════════════════════════════════

        // Build the rich guest message (packing list, weather, pickup,
        // contact block) once — shared verbatim by both channels.
        $message = $this->buildMessageViaCommand(app(TourSendReminders::class), $inquiry, $departure);

        // [bool $success, ?string $error, bool $isTransient]
        [$success, $errorMsg, $isTransient] = match ($channel) {
            'email'  => $this->sendViaEmail($recipient, $inquiry, $message),
            default  => $this->sendViaWhatsApp($recipient, $message),
        };

        // Always log to tour_reminder_logs for audit trail.
        DB::table('tour_reminder_logs')->insert([
            'booking_inquiry_id' => $inquiry->id,
            'channel' => $channel,
            'phone' => $recipient, // holds phone OR email (col widened in Phase 28)
            'status' => $success ? 'sent' : 'failed',
            'error_message' => $success ? null : $errorMsg,
            'scheduled_for_date' => $inquiry->travel_date?->format('Y-m-d'),
            'reminded_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // ═══════════════════════════════════════════════════════════════
        // Post-send state update (no lock needed — sent_at/stamp wins)
        // ═══════════════════════════════════════════════════════════════

        if ($success) {
            // ── Confirmed success — the single exactly-once close ────
            $inquiry->forceFill([
                'guest_reminder_status' => BookingInquiry::REMINDER_STATUS_SENT,
                'guest_reminder_sent_at' => now(),
                'guest_reminder_last_error' => null,
            ])->save();

            Log::info('TourReminderDispatcher: guest reminder sent ✅', [
                'inquiry_id' => $inquiry->id,
                'reference' => $inquiry->reference,
                'source' => $source,
                'channel' => $channel,
                'lead_time_minutes' => $minutesUntil,
                'attempt_number' => $guardResult['attempt_number'] ?? null,
            ]);

            return [
                'ok' => true,
                'channel' => $channel,
                'lead_time_minutes' => $minutesUntil,
            ];
        }

        $errorMsg ??= 'unknown error';

        if ($isTransient) {
            // Transient (timeout / connection / both mailers down) →
            // "unknown": the message may or may not have been delivered.
            // We MUST NOT keep retrying.
            $inquiry->forceFill([
                'guest_reminder_status' => BookingInquiry::REMINDER_STATUS_UNKNOWN,
                'guest_reminder_last_error' => $errorMsg,
                // DO NOT clear last_attempted_at — it is the throttle anchor.
            ])->save();

            Log::warning('TourReminderDispatcher: transient send error — status UNKNOWN, MANUAL REVIEW REQUIRED', [
                'inquiry_id' => $inquiry->id,
                'reference' => $inquiry->reference,
                'source' => $source,
                'channel' => $channel,
                'error' => $errorMsg,
                'attempt_number' => $guardResult['attempt_number'] ?? null,
                'guest_name' => $inquiry->customer_name,
            ]);

            return ['ok' => false, 'reason' => $channel === 'email' ? 'email_unknown' : 'wa_timeout', 'error' => $errorMsg];
        }

        // Clear failure — the channel definitively rejected the send.
        $inquiry->forceFill([
            'guest_reminder_status' => BookingInquiry::REMINDER_STATUS_FAILED,
            'guest_reminder_last_error' => $errorMsg,
        ])->save();

        Log::warning('TourReminderDispatcher: send failed', [
            'inquiry_id' => $inquiry->id,
            'reference' => $inquiry->reference,
            'source' => $source,
            'channel' => $channel,
            'error' => $errorMsg,
            'attempt_number' => $guardResult['attempt_number'] ?? null,
        ]);

        return ['ok' => false, 'reason' => $channel === 'email' ? 'email_failed' : 'wa_failed', 'error' => $errorMsg];
    }

    /**
     * Pick the delivery channel for this inquiry.
     *
     * @return array{0: 'whatsapp'|'email'|null, 1: ?string}
     *         [channel, recipient] — recipient is the normalized phone or
     *         the validated email; [null, null] when no contact is usable.
     */
    private function resolveChannel(BookingInquiry $inquiry): array
    {
        $phone = $this->whatsApp->normalizePhone($inquiry->customer_phone);
        if ($phone) {
            return ['whatsapp', $phone];
        }

        if (config('tour_experience.email_fallback_enabled')) {
            $email = BookingInquiry::normalizeEmail($inquiry->customer_email);
            if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return ['email', $email];
            }
        }

        return [null, null];
    }

    /**
     * Send the reminder over WhatsApp.
     *
     * @return array{0: bool, 1: ?string, 2: bool} [success, error, isTransient]
     */
    private function sendViaWhatsApp(string $phone, string $message): array
    {
        $result = $this->whatsApp->send($phone, $message);

        if ($result->success) {
            return [true, null, false];
        }

        $error = $result->error ?? 'unknown error';
        $lower = strtolower($error);
        $isTransient = str_contains($lower, 'timeout')
            || str_contains($lower, 'timed out')
            || str_contains($lower, 'connection');

        return [false, $error, $isTransient];
    }

    /**
     * Send the reminder over email (OTA fallback).
     *
     * Success = an SMTP server (Zoho primary, Resend fallback) ACCEPTED the
     * message. We cannot observe an async bounce from an OTA relay, so an
     * accepted-but-later-bounced email is recorded as sent (documented
     * residual risk; bounce parsing is a separate future ticket).
     *
     * @return array{0: bool, 1: ?string, 2: bool} [success, error, isTransient]
     */
    private function sendViaEmail(string $email, BookingInquiry $inquiry, string $message): array
    {
        try {
            Mail::to($email)->send(new TourGuestReminderMail(
                reference: (string) ($inquiry->reference ?? 'Booking'),
                bodyText: $message,
            ));

            return [true, null, false];
        } catch (Throwable $e) {
            $error = $e->getMessage();
            $lower = strtolower($error);
            // Transport/connection/timeout (e.g. both mailers down) = retry-able.
            // Anything else (malformed address, RFC reject) = hard failure.
            $isTransient = str_contains($lower, 'timeout')
                || str_contains($lower, 'timed out')
                || str_contains($lower, 'connection')
                || str_contains($lower, 'could not')
                || str_contains($lower, 'temporarily');

            return [false, $error, $isTransient];
        }
    }

    /**
     * No usable phone or email. Alert the operator once via Telegram, then
     * mark the booking SUPPRESSED so the daily + hourly runs do not re-alert.
     */
    private function handleNoContact(BookingInquiry $inquiry, string $source, Carbon $now): array
    {
        try {
            $this->ownerAlert->sendOpsAlert(implode("\n", [
                '⚠️ <b>Guest reminder — no contact</b>',
                '',
                "Booking: <b>{$inquiry->reference}</b>",
                'Guest: '.($inquiry->customer_name ?: '—'),
                'Tour: '.($inquiry->tour_name_snapshot ?: '—'),
                'Departure: '.($inquiry->travel_date?->format('D, d M Y') ?: '—'),
                '',
                'No phone and no email — send the pre-tour reminder manually'
                .' (e.g. via the OTA platform). This alert fires once.',
                url('/admin/booking-inquiries/'.$inquiry->id.'/edit'),
            ]));
        } catch (Throwable $e) {
            Log::warning('TourReminderDispatcher: no-contact ops alert failed', [
                'inquiry_id' => $inquiry->id,
                'reference' => $inquiry->reference,
                'error' => $e->getMessage(),
            ]);
        }

        // Suppress to prevent repeat alerts. Idempotent: a row already
        // suppressed is skipped at the top by the status fast-paths.
        $inquiry->forceFill([
            'guest_reminder_status' => BookingInquiry::REMINDER_STATUS_SUPPRESSED,
            'guest_reminder_last_error' => 'no_contact — no phone or email; operator alerted',
            'guest_reminder_last_attempted_at' => $now,
        ])->save();

        Log::warning('TourReminderDispatcher: no contact — operator alerted, booking suppressed', [
            'inquiry_id' => $inquiry->id,
            'reference' => $inquiry->reference,
            'source' => $source,
        ]);

        return ['ok' => false, 'reason' => 'no_contact'];
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
