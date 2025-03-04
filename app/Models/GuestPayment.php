<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GuestPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'guest_id',
        'booking_id',
        'amount',
        'payment_date',
        'payment_method',
        'payment_status',
    ];

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }   
}
