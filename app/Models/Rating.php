<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Rating extends Model
{
    use HasFactory;

    protected $fillable = ['tour_booking_id', 'driver_id', 'review_source', 'review_score', 'comments' ];

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    } 

    public function tour_booking(): BelongsTo
    {
        return $this->belongsTo(TourBooking::class);
    } 
   
}
