<?php

declare(strict_types=1);

namespace App\Services\GuestExperience;

use App\Enums\GuestExperienceMessageType;
use App\Models\BookingInquiry;
use App\Models\GuestExperienceMessage;
use App\Services\Messaging\WhatsAppSender;
use App\Services\OwnerAlertService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends one due guest experience message, idempotently (Phase 29).
 *
 * Idempotency is layered:
 *   - UNIQUE(booking_inquiry_id, message_type) guarantees one row/touchpoint.
 *   - A status compare-and-swap (UPDATE … WHERE status='pending') ensures a
 *     single concurrent run claims the row; losers no-op. No heavy row lock
 *     is needed because the unique constraint already removes the "two rows"
 *     race the 24h reminder had to guard against.
 *   - A 'sending' row left by a crash is swept to 'unknown' (manual review),
 *     never auto-retried — fail-closed (risk a missed message, never a dupe).
 *
 * v1 is WhatsApp-only. No phone → 'skipped' (+ one ops alert for the welcome
 * so an operator can reach OTA guests manually). Never throws.
 */
class GuestExperienceDispatcher
{
    public function __construct(
        private readonly WhatsAppSender $whatsApp,
        private readonly MessageCatalog $catalog,
        private readonly OwnerAlertService $ownerAlert,
    ) {}

    /**
     * @return array{ok: bool, reason?: string, status?: string}
     */
    public function send(GuestExperienceMessage $message, bool $dryRun = false): array
    {
        $inquiry = $message->bookingInquiry;
        if ($inquiry === null) {
            return $this->mark($message, GuestExperienceMessage::STATUS_SKIPPED, 'booking missing', dryRun: $dryRun, reason: 'no_booking');
        }

        // ── Re-validate eligibility at send time ────────────────────────
        if ($inquiry->status !== BookingInquiry::STATUS_CONFIRMED) {
            return $this->mark($message, GuestExperienceMessage::STATUS_SKIPPED, 'booking not confirmed', dryRun: $dryRun, reason: 'not_confirmed');
        }
        if ($inquiry->experience_messages_opted_out) {
            return $this->mark($message, GuestExperienceMessage::STATUS_SKIPPED, 'opted out', dryRun: $dryRun, reason: 'opted_out');
        }

        // ── Too late? A welcome a day after pickup is worse than none. ──
        $lateBy = $message->due_at
            ? Carbon::now()->diffInMinutes($message->due_at, false) * -1
            : 0;
        if ($lateBy > (int) config('guest_experience.max_lateness_minutes')) {
            return $this->mark($message, GuestExperienceMessage::STATUS_SKIPPED, "past due by {$lateBy} min", dryRun: $dryRun, reason: 'too_late');
        }

        // ── Resolve type + content ──────────────────────────────────────
        $type = GuestExperienceMessageType::tryFrom($message->message_type);
        $body = $type ? $this->catalog->render($inquiry, $type) : null;
        if ($body === null) {
            return $this->mark($message, GuestExperienceMessage::STATUS_SKIPPED, 'no catalog content', dryRun: $dryRun, reason: 'no_content');
        }

        // ── Channel: WhatsApp only (v1) ─────────────────────────────────
        $phone = $this->whatsApp->normalizePhone($inquiry->customer_phone);
        if (! $phone) {
            // OTA guests have no phone. Welcome → alert an operator to reach
            // out via the OTA platform; other types skip silently.
            if ($type === GuestExperienceMessageType::PostPickupWelcome) {
                $this->alertNoPhone($inquiry);
            }

            return $this->mark($message, GuestExperienceMessage::STATUS_SKIPPED, 'no phone', dryRun: $dryRun, reason: 'no_phone');
        }

        if ($dryRun) {
            Log::info('GuestExperienceDispatcher: [DRY] would send', [
                'message_id' => $message->id,
                'reference' => $inquiry->reference,
                'type' => $message->message_type,
                'phone' => $phone,
            ]);

            return ['ok' => true, 'reason' => 'dry_run'];
        }

        // ── Claim the row (compare-and-swap) ────────────────────────────
        $claimed = DB::table('guest_experience_messages')
            ->where('id', $message->id)
            ->where('status', GuestExperienceMessage::STATUS_PENDING)
            ->update([
                'status' => GuestExperienceMessage::STATUS_SENDING,
                'channel' => 'whatsapp',
                'last_attempted_at' => now(),
                'attempt_count' => DB::raw('attempt_count + 1'),
                'idempotency_key' => 'gem:'.$message->id.':'.$message->message_type,
                'last_error' => null,
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            // Another run claimed it (or it's no longer pending). No-op.
            return ['ok' => false, 'reason' => 'already_claimed'];
        }

        // ── Send — outside any lock ─────────────────────────────────────
        $result = $this->whatsApp->send($phone, $body);

        if ($result->success) {
            $message->forceFill([
                'status' => GuestExperienceMessage::STATUS_SENT,
                'channel' => 'whatsapp',
                'sent_at' => now(),
                'last_error' => null,
            ])->save();

            Log::info('GuestExperienceDispatcher: sent ✅', [
                'message_id' => $message->id,
                'reference' => $inquiry->reference,
                'type' => $message->message_type,
            ]);

            return ['ok' => true, 'status' => GuestExperienceMessage::STATUS_SENT];
        }

        // Failure: timeout/connection → unknown (no retry); else failed.
        $error = $result->error ?? 'unknown error';
        $lower = strtolower($error);
        $transient = str_contains($lower, 'timeout')
            || str_contains($lower, 'timed out')
            || str_contains($lower, 'connection');

        $status = $transient
            ? GuestExperienceMessage::STATUS_UNKNOWN
            : GuestExperienceMessage::STATUS_FAILED;

        $message->forceFill([
            'status' => $status,
            'last_error' => $error,
        ])->save();

        Log::warning('GuestExperienceDispatcher: send failed', [
            'message_id' => $message->id,
            'reference' => $inquiry->reference,
            'type' => $message->message_type,
            'status' => $status,
            'error' => $error,
        ]);

        return ['ok' => false, 'reason' => $status, 'status' => $status];
    }

    /**
     * Sweep a row stuck in 'sending' (a crashed prior send) to 'unknown'
     * for manual review. Never auto-retried.
     */
    public function sweepStale(GuestExperienceMessage $message): void
    {
        $message->forceFill([
            'status' => GuestExperienceMessage::STATUS_UNKNOWN,
            'last_error' => 'stale sending — prior dispatch likely crashed; manual review',
        ])->save();

        Log::warning('GuestExperienceDispatcher: stale sending swept to unknown', [
            'message_id' => $message->id,
            'reference' => $message->bookingInquiry?->reference,
            'type' => $message->message_type,
        ]);
    }

    private function alertNoPhone(BookingInquiry $inquiry): void
    {
        try {
            $this->ownerAlert->sendOpsAlert(implode("\n", [
                '👋 <b>Experience message — no phone</b>',
                '',
                "Booking: <b>{$inquiry->reference}</b>",
                'Guest: '.($inquiry->customer_name ?: '—'),
                'Tour: '.($inquiry->tour_name_snapshot ?: '—'),
                '',
                'No WhatsApp number — welcome the guest manually (e.g. via the'
                .' OTA platform). This fires once for the welcome only.',
                url('/admin/booking-inquiries/'.$inquiry->id.'/edit'),
            ]));
        } catch (Throwable $e) {
            Log::warning('GuestExperienceDispatcher: no-phone ops alert failed', [
                'inquiry_id' => $inquiry->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{ok: bool, reason: string, status: string}
     */
    private function mark(
        GuestExperienceMessage $message,
        string $status,
        string $note,
        bool $dryRun,
        string $reason,
    ): array {
        if (! $dryRun) {
            $message->forceFill([
                'status' => $status,
                'last_error' => $note,
                'last_attempted_at' => now(),
            ])->save();
        }

        return ['ok' => false, 'reason' => $reason, 'status' => $status];
    }
}
