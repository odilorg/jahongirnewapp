<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Log;
use App\Models\Chat;
use App\Models\ScheduledMessage;

class Booking extends Model
{
    use HasFactory;

    protected $fillable = [
        'guest_id',
        'booking_start_date_time',
        'pickup_location',
        'dropoff_location',
        'special_requests',
        'group_name',
        'driver_id',
        'guide_id',
        'tour_id',
        'payment_status',
        'payment_method',
        'amount',
        'booking_status',
        'booking_source'
    ];

    protected static function boot()
    {
        parent::boot();

        static::created(function ($booking) {
            $booking->scheduleNotifications();
        });

        static::updated(function ($booking) {
            $booking->updateScheduledNotifications();
        });
    }

    /**
     * Schedule notifications for a booking.
     * - Advance Notification at 48 hours before the tour.
     * - Final Countdown Alerts at 24 hours and 1 hour before the tour.
     * - If none of these times are in the future, send an immediate notification.
     */
    public function scheduleNotifications()
    {
        // Ensure the booking has a tour and a start date.
        if (!$this->tour || !$this->booking_start_date_time) {
            return;
        }

        $bookingStart = Carbon::parse($this->booking_start_date_time);
        $now = Carbon::now();

        // Define notification times relative to the booking start.
        $advancedTime         = $bookingStart->copy()->subHours(48);
        $finalCountdownTime24 = $bookingStart->copy()->subHours(24);
        $finalCountdownTime1  = $bookingStart->copy()->subHour();

        // Retrieve the Chat records (for example, using Telegram chat IDs).
        $desiredTelegramIds = [
            '38738713',
            '5164858668',
        ];
        $chatRecords = Chat::whereIn('chat_id', $desiredTelegramIds)->get();

        if ($chatRecords->isEmpty()) {
            Log::warning("No matching chats found for Booking #{$this->id}. Check the 'chat_id' values in the chats table.");
            return;
        }

        // Flag to check if at least one scheduled time was in the future.
        $notificationScheduled = false;

        // Schedule Advanced Notification (48 hours before)
        if ($advancedTime->isFuture()) {
            ScheduledMessage::create([
                'booking_id'   => $this->id,
                'message'      => "Advanced Notification: The tour '{$this->tour->title}' starts on {$this->booking_start_date_time}. Please prepare accordingly.",
                'scheduled_at' => $advancedTime,
                'status'       => 'pending',
                'frequency'    => 'none',
            ])->chats()->attach($chatRecords->pluck('id')->toArray());

            $notificationScheduled = true;
        } else {
            Log::info("Advanced notification time (48 hours before) has passed for Booking #{$this->id}.");
        }

        // Schedule Final Countdown Alert (24 hours before)
        if ($finalCountdownTime24->isFuture()) {
            ScheduledMessage::create([
                'booking_id'   => $this->id,
                'message'      => "Final Countdown Alert: The tour '{$this->tour->title}' starts in 24 hours on {$this->booking_start_date_time}.",
                'scheduled_at' => $finalCountdownTime24,
                'status'       => 'pending',
                'frequency'    => 'none',
            ])->chats()->attach($chatRecords->pluck('id')->toArray());

            $notificationScheduled = true;
        } else {
            Log::info("Final Countdown Alert (24 hrs) time has passed for Booking #{$this->id}.");
        }

        // Schedule Final Countdown Alert (1 hour before)
        if ($finalCountdownTime1->isFuture()) {
            ScheduledMessage::create([
                'booking_id'   => $this->id,
                'message'      => "Final Countdown Alert: The tour '{$this->tour->title}' starts in 1 hour on {$this->booking_start_date_time}.",
                'scheduled_at' => $finalCountdownTime1,
                'status'       => 'pending',
                'frequency'    => 'none',
            ])->chats()->attach($chatRecords->pluck('id')->toArray());

            $notificationScheduled = true;
        } else {
            Log::info("Final Countdown Alert (1 hr) time has passed for Booking #{$this->id}.");
        }

        // If none of the scheduled notification times are in the future,
        // but the booking start time is still in the future,
        // send an immediate notification.
        if (!$notificationScheduled && $bookingStart->isFuture()) {
            $this->sendImmediateNotification($chatRecords, $bookingStart);
        }
    }

    /**
     * Send an immediate notification for last-minute bookings.
     */
    public function sendImmediateNotification($chatRecords, $bookingStart = null)
    {
        $bookingStart = $bookingStart ?: Carbon::parse($this->booking_start_date_time);

        $scheduledMessage = ScheduledMessage::create([
            'booking_id'   => $this->id,
            'message'      => "Immediate Alert: The tour '{$this->tour->title}' is starting soon on {$this->booking_start_date_time}. Please prepare immediately.",
            'scheduled_at' => Carbon::now(),
            'status'       => 'pending',
            'frequency'    => 'none',
        ]);

        $scheduledMessage->chats()->attach($chatRecords->pluck('id')->toArray());
        Log::info("Immediate notification sent for Booking #{$this->id}.");
    }

    /**
     * Update scheduled notifications when a booking is modified.
     * This deletes any existing notifications for the booking and re-schedules them.
     */
    public function updateScheduledNotifications()
    {
        if (!$this->tour || !$this->booking_start_date_time) {
            return;
        }

        // Remove all scheduled messages linked to this booking.
        ScheduledMessage::where('booking_id', $this->id)->delete();

        // Re-schedule notifications based on the new booking start time.
        $this->scheduleNotifications();
    }

    // ---------------------------
    // Relationships
    // ---------------------------
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function tour(): BelongsTo
    {
        return $this->belongsTo(Tour::class);
    }

    public function guide(): BelongsTo
    {
        return $this->belongsTo(Guide::class);
    }

    public function guestPayments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(GuestPayment::class, 'booking_id');
    }
}
