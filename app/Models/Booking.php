<?php

namespace App\Models;

use Carbon\Carbon;
use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Booking extends Model
{

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
     */
    public function scheduleTelegramMessages()
    {
        if (!$this->tour || !$this->booking_start_date_time) {
            return;
        }

        $startReminderDate = Carbon::parse($this->booking_start_date_time)->subDays(3);
        $tourStartDate = Carbon::parse($this->booking_start_date_time);

        // List of predefined chat IDs
        $chatIds = [
            '38738713',  // Chat ID 1
            '5164858668',  // Chat ID 2
        ];

        foreach ($chatIds as $chatId) {
            for ($date = $startReminderDate; $date->lessThanOrEqualTo($tourStartDate); $date->addDay()) {
                ScheduledMessage::create([
                    'message' => "Reminder: The tour '{$this->tour->title}' is happening soon! It starts on {$this->booking_start_date_time}.",
                    'scheduled_at' => $date,
                    'status' => 'pending',
                    'chat_id' => $chatId,
                    'frequency' => 'none',
                ]);
            }
        }
    }

    /**
     * Update scheduled messages when a booking is modified.
     */
    public function updateScheduledMessages()
    {
        if (!$this->tour || !$this->booking_start_date_time) {
            return;
        }

        // Delete old scheduled messages for this booking
        ScheduledMessage::where('message', 'like', "%The tour '{$this->tour->title}' is happening soon!%")->delete();

        // Reschedule messages
        $this->scheduleTelegramMessages();
    }



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
    ] ;
     public function guest() : BelongsTo {
        
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

    public function guestPayments()
{
    return $this->hasMany(GuestPayment::class, 'booking_id');
}

    
}
