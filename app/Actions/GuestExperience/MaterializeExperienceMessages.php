<?php

declare(strict_types=1);

namespace App\Actions\GuestExperience;

use App\Models\BookingInquiry;
use App\Models\GuestExperienceMessage;
use App\Services\GuestExperience\MessageCatalog;
use App\Services\TourReminderDispatcher;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Pre-creates the guest experience message rows for a confirmed booking
 * (Phase 29).
 *
 * Idempotent: relies on the UNIQUE(booking_inquiry_id, message_type)
 * constraint via updateOrCreate, so re-running on a booking is safe and
 * never duplicates. Skips message types whose due_at is already in the
 * past at materialization time (e.g. a booking confirmed after the welcome
 * window) so the engine never back-fires a stale touchpoint.
 */
class MaterializeExperienceMessages
{
    public function __construct(
        private readonly MessageCatalog $catalog,
        private readonly TourReminderDispatcher $reminders,
    ) {}

    /**
     * @return int number of pending rows created/refreshed
     */
    public function handle(BookingInquiry $inquiry): int
    {
        if (! config('guest_experience.enabled')) {
            return 0;
        }
        if ($inquiry->experience_messages_opted_out) {
            return 0;
        }
        if ($inquiry->status !== BookingInquiry::STATUS_CONFIRMED) {
            return 0;
        }
        if (! $this->catalog->appliesTo($inquiry)) {
            return 0;
        }

        $departure = $this->reminders->departureAt($inquiry);
        if ($departure === null) {
            return 0;
        }

        $now = Carbon::now('Asia/Tashkent');
        $created = 0;

        foreach ($this->catalog->typesFor($inquiry) as $type) {
            $dueAt = $type->dueAt($departure);

            // Don't materialize a message whose moment has already passed —
            // a late "welcome" is worse than none.
            if ($dueAt->lessThan($now)) {
                continue;
            }

            $existing = GuestExperienceMessage::query()
                ->where('booking_inquiry_id', $inquiry->id)
                ->where('message_type', $type->value)
                ->first();

            // Store due_at in the APP timezone (Asia/Samarkand), matching how
            // Laravel reads timestamps back and how the cron's now() compares.
            // The enum returns due times in Asia/Tashkent (same +5 offset); a
            // ->utc() conversion here previously stored them 5h early, firing
            // every message at ~04:00 local instead of its intended time
            // (incident 2026-06-15: 04:30 welcome to a guest).
            $dueLocal = $dueAt->copy()->setTimezone(config('app.timezone'));

            if ($existing === null) {
                GuestExperienceMessage::create([
                    'booking_inquiry_id' => $inquiry->id,
                    'message_type' => $type->value,
                    'status' => GuestExperienceMessage::STATUS_PENDING,
                    'due_at' => $dueLocal,
                ]);
                $created++;
            } elseif ($existing->status === GuestExperienceMessage::STATUS_PENDING) {
                // Still pending → safe to re-time (e.g. travel_date changed).
                // Never resurrect a sent/skipped/suppressed/failed row.
                $existing->forceFill(['due_at' => $dueLocal])->save();
                $created++;
            }
        }

        Log::info('MaterializeExperienceMessages: done', [
            'inquiry_id' => $inquiry->id,
            'reference' => $inquiry->reference,
            'tour_slug' => $inquiry->tour_slug,
            'rows' => $created,
        ]);

        return $created;
    }
}
