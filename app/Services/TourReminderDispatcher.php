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
 * Idempotency contract
 *   - `guest_reminder_sent_at` is the load-bearing marker.
 *   - Stamped via forceFill()->save() ONLY after a successful WhatsApp
 *     send (per the rule from incident 2026-04-26 — never update() for
 *     system fields, never stamp on failure for retry-friendly cases).
 *   - Every caller MUST re-check the marker before sending. Cron and
 *     fast-path can collide; whichever wins the marker stamp first
 *     causes the loser to no-op.
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
     * Returns:
     *   ['ok' => true,  'msg_id' => …, 'lead_time_minutes' => N]
     *   ['ok' => false, 'reason' => 'already_sent'|'no_phone'|'wa_failed'|'out_of_window'|…]
     *
     * Never throws — failures are returned + logged so callers (cron,
     * job, fast path) can branch on outcome.
     */
    public function sendGuestReminder(BookingInquiry $inquiry, string $source): array
    {
        // Re-check inside the dispatcher so cron+fast-path collisions
        // resolve to one send.
        if ($inquiry->guest_reminder_sent_at !== null) {
            return ['ok' => false, 'reason' => 'already_sent'];
        }

        if ($inquiry->status !== BookingInquiry::STATUS_CONFIRMED) {
            return ['ok' => false, 'reason' => 'not_confirmed'];
        }

        $departure = $this->departureAt($inquiry);
        if ($departure === null) {
            return ['ok' => false, 'reason' => 'no_departure_at'];
        }

        $now = Carbon::now('Asia/Tashkent');
        $minutesUntil = $now->diffInMinutes($departure, false);
        if ($minutesUntil < 0 || $minutesUntil > 24 * 60) {
            return ['ok' => false, 'reason' => 'out_of_window'];
        }

        $phone = $this->whatsApp->normalizePhone($inquiry->customer_phone);
        if (! $phone) {
            return ['ok' => false, 'reason' => 'no_phone'];
        }

        // Reuse the existing rich message builder (packing list, weather,
        // pickup block, contact block) by delegating to the Console
        // command's builder. Acceptable coupling for v1 — Phase 2 of this
        // refactor will fully extract the builder into this service.
        $command = app(TourSendReminders::class);
        $message = $this->buildMessageViaCommand($command, $inquiry, $departure);

        $result = $this->whatsApp->send($phone, $message);

        DB::table('tour_reminder_logs')->insert([
            'booking_inquiry_id' => $inquiry->id,
            'channel'            => 'whatsapp',
            'phone'              => $phone,
            'status'             => $result->success ? 'sent' : 'failed',
            'error_message'      => $result->success ? null : $result->error,
            'scheduled_for_date' => $inquiry->travel_date->format('Y-m-d'),
            'reminded_at'        => now(),
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        if (! $result->success) {
            Log::warning('TourReminderDispatcher: WhatsApp send failed', [
                'inquiry_id' => $inquiry->id,
                'reference'  => $inquiry->reference,
                'source'     => $source,
                'error'      => $result->error,
            ]);
            return ['ok' => false, 'reason' => 'wa_failed', 'error' => $result->error];
        }

        // Stamp the marker — bypass mass-assignment for the system field
        // per the rule baked in 2026-04-26 (incident).
        $inquiry->forceFill(['guest_reminder_sent_at' => now()])->save();

        Log::info('TourReminderDispatcher: guest reminder sent', [
            'inquiry_id'        => $inquiry->id,
            'reference'         => $inquiry->reference,
            'source'            => $source,
            'lead_time_minutes' => $minutesUntil,
            'msg_id'            => null,
        ]);

        return [
            'ok'                => true,
            'msg_id'            => null,
            'lead_time_minutes' => $minutesUntil,
        ];
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
        // buildGuestMessage is private — expose via public proxy to avoid
        // reflection. The proxy method is added on TourSendReminders in
        // the same commit as this service.
        return $command->buildGuestMessagePublic($inquiry, $dateLabel);
    }
}
