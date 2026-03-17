<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GygBooking extends Model
{
    protected $table = 'gyg_bookings';

    protected $fillable = [
        'booking_reference',
        'reservation_reference',
        'gyg_booking_reference',
        'gyg_activity_reference',
        'gyg_product_id',
        'slot_datetime',
        'booking_items',
        'travelers',
        'traveler_hotel',
        'language',
        'comment',
        'currency',
        'tickets',
        'status',
    ];

    protected $casts = [
        'slot_datetime' => 'datetime',
        'booking_items' => 'array',
        'travelers' => 'array',
        'traveler_hotel' => 'array',
        'tickets' => 'array',
    ];
}
