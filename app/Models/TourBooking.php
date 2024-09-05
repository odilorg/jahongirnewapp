<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TourBooking extends Model
{
    use HasFactory;
    protected $fillable = ['status', 'payment_status', 'group_number', 'tour_id', 'guest_id', 'driver_id', 'guide_id', 'number_of_adults', 'number_of_children', 'special_requests', 'pickup_location', 'dropoff_location'];

    
    


    public function tour_payments(): HasMany
    {
        return $this->Hasmany(TourPayment::class);
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
    // public function guest(): BelongsTo
    // {
    //     return $this->belongsTo(Guest::class);
    // }
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    /**
     * Relationship with TourBookingRepeater model.
     * A TourBooking has many TourBookingRepeater records.
     */
    public function tourBookingRepeaters(): HasMany
    {
        return $this->hasMany(TourBookingRepeater::class);
    }

    
}
