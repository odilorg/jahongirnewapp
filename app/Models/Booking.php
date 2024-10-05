<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
        'tour_id'
    ] ;

    // public function bookingTours(): HasMany
    // {
    //     return $this->hasMany(BookingTour::class);
    // }
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

    
}
