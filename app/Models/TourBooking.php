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
    
    

    // public function guest(): BelongsTo
    // {
    //     return $this->belongsTo(Guest::class);
    // }

    // public function driver(): BelongsTo
    // {
    //     return $this->belongsTo(Driver::class);
    // }

    // public function guide(): BelongsTo
    // {
    //     return $this->belongsTo(Guide::class);
    // }
}
