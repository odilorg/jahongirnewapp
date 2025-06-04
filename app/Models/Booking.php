<?php

namespace App\Models;

use Carbon\Carbon;
use App\Models\Chat;
use App\Jobs\GenerateBookingPdf;
use App\Models\ScheduledMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

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
        'booking_source',
        'payment_link',
        'number_of_people',
        'file_name',
        'booking_number',
        'booking_end_date_time',
        'booking_id',
    ];

    protected static function boot()
    {
        parent::boot();

        static::created(function ($booking) {
            // Generate booking number like "BOOK-2025-001"
            Log::info("Booking created with ID: {$booking->id}");

        $month = now()->month;
        $year = $month >= 11 ? now()->year + 1 : now()->year;

        $booking->booking_number = 'BOOK-' . $year . '-' . str_pad($booking->id, 3, '0', STR_PAD_LEFT);

        Log::info("Generated booking number: {$booking->booking_number}");


        $booking->saveQuietly();

        $booking->scheduleNotifications();

        // Dispatch PDF job (if used)
        // GenerateBookingPdf::dispatch($booking);
            $booking->scheduleNotifications();

            // Dispatch PDF generation
        GenerateBookingPdf::dispatch($booking);
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
        // Ensure the booking has a tour, a guest, and a start date.
        if (!$this->tour || !$this->guest || !$this->booking_start_date_time) {
            Log::warning("Missing tour, guest, or start date for Booking #{$this->id}.");
            return;
        }

        $bookingStart = Carbon::parse($this->booking_start_date_time);

        // Build common booking details for the message:
        $tourTitle         = $this->tour->title;
        $guestName         = $this->guest->full_name;
        $guestNumber         = $this->guest->number_of_people;
        $guestEmail        = $this->guest->email ?? '(no email)';
        $guestPhone        = $this->guest->phone ?? '(no phone)';
        $pickupLocation    = $this->pickup_location;
        $dropoffLocation   = $this->dropoff_location;
        $specialRequests   = $this->special_requests;
        $bookingSource     = $this->booking_source;

        $driverName        = $this->driver ? $this->driver->full_name : '(no driver)';
        $driverPhone1      = $this->driver ? $this->driver->phone1 : '';
        $driverPhone2      = $this->driver ? $this->driver->phone2 : '';

        $guideName         = $this->guide ? $this->guide->full_name : '(no guide)';
        $guidePhone1       = $this->guide ? $this->guide->phone1 : '';
        $guidePhone2       = $this->guide ? $this->guide->phone2 : '';

        // Prepare the text we’ll inject into each message:
        $sharedInfo = "Tour: {$tourTitle}\n"
            ."Guest: {$guestName} (Email: {$guestEmail}, Phone: {$guestPhone})\n"
            ."Number of people: {$guestNumber}\n"
            ."Pickup: {$pickupLocation}\n"
            ."Dropoff: {$dropoffLocation}\n"
            ."Special Requests: {$specialRequests}\n"
            ."Booking Source: {$bookingSource}\n"
            ."Driver: {$driverName} (Phones: {$driverPhone1}, {$driverPhone2})\n"
            ."Guide: {$guideName} (Phones: {$guidePhone1}, {$guidePhone2})\n"
            ."Scheduled Start: {$this->booking_start_date_time}";

        // Define notification times relative to the booking start.
        $advancedTime         = $bookingStart->copy()->subHours(48);
        $finalCountdownTime24 = $bookingStart->copy()->subHours(24);
        $finalCountdownTime1  = $bookingStart->copy()->subHour();
        $now = Carbon::now();

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
                'message'      => "Advanced Notification:\n{$sharedInfo}\n\nPlease prepare accordingly (48 hours remaining).",
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
                'message'      => "Final Countdown Alert (24 hours):\n{$sharedInfo}",
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
                'message'      => "Final Countdown Alert (1 hour):\n{$sharedInfo}",
                'scheduled_at' => $finalCountdownTime1,
                'status'       => 'pending',
                'frequency'    => 'none',
            ])->chats()->attach($chatRecords->pluck('id')->toArray());

            $notificationScheduled = true;
        } else {
            Log::info("Final Countdown Alert (1 hr) time has passed for Booking #{$this->id}.");
        }

        // If none of the scheduled notification times are in the future but the
        // booking start time itself is still in the future, send an immediate notification.
        if (!$notificationScheduled && $bookingStart->isFuture()) {
            $this->sendImmediateNotification($chatRecords, $bookingStart, $sharedInfo);
        }
    }

    /**
     * Send an immediate notification for last-minute bookings.
     */
    public function sendImmediateNotification($chatRecords, $bookingStart = null, $sharedInfo = '')
    {
        $bookingStart = $bookingStart ?: Carbon::parse($this->booking_start_date_time);

        // If for some reason the info wasn’t passed, re-construct it here
        if (!$sharedInfo) {
            $tourTitle         = $this->tour ? $this->tour->title : '';
            $guestName         = $this->guest ? $this->guest->full_name : '';
            $guestNumber         = $this->guest->number_of_people;
            $guestEmail        = $this->guest ? $this->guest->email : '(no email)';
            $guestPhone        = $this->guest ? $this->guest->phone : '(no phone)';
            $pickupLocation    = $this->pickup_location;
            $dropoffLocation   = $this->dropoff_location;
            $specialRequests   = $this->special_requests;
            $bookingSource     = $this->booking_source;

            $driverName        = $this->driver ? $this->driver->full_name : '(no driver)';
            $driverPhone1      = $this->driver ? $this->driver->phone1 : '';
            $driverPhone2      = $this->driver ? $this->driver->phone2 : '';

            $guideName         = $this->guide ? $this->guide->full_name : '(no guide)';
            $guidePhone1       = $this->guide ? $this->guide->phone1 : '';
            $guidePhone2       = $this->guide ? $this->guide->phone2 : '';

            $sharedInfo = "Tour: {$tourTitle}\n"
                ."Guest: {$guestName} (Email: {$guestEmail}, Phone: {$guestPhone})\n"
                ."Number of people: {$guestNumber}\n"
                ."Pickup: {$pickupLocation}\n"
                ."Dropoff: {$dropoffLocation}\n"
                ."Special Requests: {$specialRequests}\n"
                ."Booking Source: {$bookingSource}\n"
                ."Driver: {$driverName} (Phones: {$driverPhone1}, {$driverPhone2})\n"
                ."Guide: {$guideName} (Phones: {$guidePhone1}, {$guidePhone2})\n"
                ."Scheduled Start: {$this->booking_start_date_time}";
        }

        $scheduledMessage = ScheduledMessage::create([
            'booking_id'   => $this->id,
            'message'      => "Immediate Alert:\n{$sharedInfo}\n\nStarting soon. Please prepare immediately.",
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

    // In app/Models/Booking.php

public function tourExpenses()
{
    return $this->hasMany(\App\Models\TourExpense::class);
}
public function getTotalExpensesAttribute()
{
    return $this->tourExpenses()->sum('amount');
}


}
