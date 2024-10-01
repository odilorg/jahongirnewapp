<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BookingTour extends Model
{
    use HasFactory;
    protected $fillable = [
        'booking_id',
        'tour_id',
        'driver_id',
        'guide_id',
        'sub_total',
        'pickup_location',
        'dropoff_location',
        'tour_start_date_time',
        'special_request',
    ];
   

}
