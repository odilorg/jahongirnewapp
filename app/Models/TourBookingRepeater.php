<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
//use Illuminate\Database\Eloquent\Relations\Pivot;


class TourBookingRepeater extends Model
{
    use HasFactory;

    protected $table = 'tour_booking_repeaters';

    protected $fillable = ['amount_paid', 'payment_date', 'status', 'payment_status', 'group_number', 'tour_id', 'guest_id', 'driver_id', 'guide_id', 'number_of_adults', 'number_of_children', 'special_requests', 'pickup_location', 'dropoff_location'];
    public function tourBooking(): BelongsTo
    {
        return $this->belongsTo(TourBooking::class);
    }

  

public function driver()
{
    return $this->belongsTo(Driver::class);
}

public function guide()
{
    return $this->belongsTo(Guide::class);
}

public function tour()
{
    return $this->belongsTo(Tour::class);
}

// public function guide()
// {
//     return $this->belongsTo(Guide::class);
// }


}
