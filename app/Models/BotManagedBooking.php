<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotManagedBooking extends Model
{
    protected $fillable = [
        'staff_request_id',
        'beds24_booking_id',
        'property_id',
        'property_name',
        'room_id',
        'room_name',
        'unit_name',
        'guest_name',
        'guest_email',
        'guest_phone',
        'check_in_date',
        'check_out_date',
        'total_price',
        'currency',
        'booking_status',
        'created_by_staff_id',
        'cancelled_by_staff_id',
        'cancellation_reason',
        'cancelled_at',
    ];

    protected $casts = [
        'check_in_date' => 'date',
        'check_out_date' => 'date',
        'total_price' => 'decimal:2',
        'cancelled_at' => 'datetime',
    ];

    // Relationships
    public function staffRequest(): BelongsTo
    {
        return $this->belongsTo(StaffBookingRequest::class, 'staff_request_id');
    }

    public function createdByStaff(): BelongsTo
    {
        return $this->belongsTo(AuthorizedStaff::class, 'created_by_staff_id');
    }

    public function cancelledByStaff(): BelongsTo
    {
        return $this->belongsTo(AuthorizedStaff::class, 'cancelled_by_staff_id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->whereIn('booking_status', ['confirmed', 'checked_in']);
    }

    public function scopeUpcoming($query)
    {
        return $query->where('booking_status', 'confirmed')
            ->where('check_in_date', '>=', now());
    }

    public function scopeToday($query)
    {
        return $query->whereDate('check_in_date', today())
            ->orWhereDate('check_out_date', today());
    }

    // Find by Beds24 booking ID
    public static function findByBeds24Id(string $beds24BookingId): ?self
    {
        return self::where('beds24_booking_id', $beds24BookingId)->first();
    }
}
