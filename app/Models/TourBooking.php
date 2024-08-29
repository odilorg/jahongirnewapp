<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class TourBooking extends Model
{
    use HasFactory;
    protected $fillable = ['group_number', 'tour_id', 'guest_id', 'driver_id', 'guide_id', 'number_of_adults', 'number_of_children', 'special_requests', 'pickup_location', 'dropoff_location'];

    
    public function tours(): BelongsToMany
    {
        return $this->belongsToMany(Tour::class, 'tour_tour_booking');
    }
    
    

    public function guests(): BelongsToMany
    {
        return $this->belongsToMany(Guest::class, 'guest_tour_booking');
    }

    public function drivers(): BelongsToMany
    {
        return $this->belongsToMany(Driver::class, 'driver_tour_booking' );
    }

    public function guides(): BelongsToMany
    {
        return $this->belongsToMany(Guide::class, 'guide_tour_booking');
    }
}
