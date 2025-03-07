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
            $booking->scheduleTelegramMessages();
        });

        static::updated(function ($booking) {
            $booking->updateScheduledMessages();
        });
    }

    /**
     * Schedule Telegram messages for a new booking.
     * This creates daily reminders from 3 days prior until the tour start date,
     * then attaches multiple chats to each scheduled message via pivot.
     */
    public function scheduleTelegramMessages()
    {
        // 1. Must have a tour and a start date.
        if (!$this->tour || !$this->booking_start_date_time) {
            return;
        }

        // 2. Calculate the date range: 3 days before up to the start date.
        $startReminderDate = Carbon::parse($this->booking_start_date_time)->subDays(3);
        $tourStartDate = Carbon::parse($this->booking_start_date_time);

        // 3. Retrieve the Chat records we want to notify.
        //    For example, the "chat_id" column in the `chats` table might be the Telegram ID:
        $desiredTelegramIds = [
            '38738713',
            '5164858668',
        ];

        // Grab Chat rows that match these Telegram IDs.
        $chatRecords = Chat::whereIn('chat_id', $desiredTelegramIds)->get();

        if ($chatRecords->isEmpty()) {
            Log::warning("No matching chats found for Booking #{$this->id}. Please check 'chat_id' values in the `chats` table.");
            return;
        }

        // 4. Loop day-by-day to create daily reminders
        for ($date = $startReminderDate->copy(); $date->lessThanOrEqualTo($tourStartDate); $date->addDay()) {
            // Create the scheduled message for this day, linking it to the booking.
            $scheduledMessage = ScheduledMessage::create([
                'booking_id'   => $this->id,
                'message'      => "Reminder: The tour '{$this->tour->title}' is happening soon! It starts on {$this->booking_start_date_time}.",
                'scheduled_at' => $date,
                'status'       => 'pending',
                'frequency'    => 'none',
            ]);

            // Attach all Chat records to this scheduled message via pivot.
            // This assumes a belongsToMany relationship: ScheduledMessage::chats()
            $scheduledMessage->chats()->attach($chatRecords->pluck('id')->toArray());
        }
    }

    /**
     * Update scheduled messages when a booking is modified.
     * If no scheduled message exists for this booking, it creates new ones.
     */
    public function updateScheduledMessages()
    {
        // Must have a tour and a start date.
        if (!$this->tour || !$this->booking_start_date_time) {
            return;
        }

        // Check if any scheduled messages already exist for this booking.
        $existingMessagesCount = ScheduledMessage::where('booking_id', $this->id)->count();

        // Only schedule messages if none exist.
        if ($existingMessagesCount === 0) {
            $this->scheduleTelegramMessages();
        } else {
            Log::info("Scheduled message(s) already exist for Booking #{$this->id}. No new messages created.");
        }
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
?>
