<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Booking extends Model
{
    use HasFactory;

    protected $fillable = [
        'guest_id',
        'grand_total',
        'payment_method',
        'payment_status',
        'notes',
        'group_name',
    ] ;

    public function bookingTours(): HasMany
    {
        return $this->hasMany(BookingTour::class);
    }
    public function guest() : BelongsTo {
        
        return $this->belongsTo(Guest::class);
    }
}
