<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TourPayment extends Model
{
    use HasFactory;
    protected $fillable = ['amount_paid', 'tour_booking_id'];

    public function tour_booking(): BelongsTo
    {
        return $this->belongsTo(TourBooking::class);
    }

}
