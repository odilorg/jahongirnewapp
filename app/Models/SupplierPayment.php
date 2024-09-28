<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierPayment extends Model
{
    use HasFactory;
    protected $fillable = ['receipt_image', 
    'payment_type', 'payment_date', 'amount_paid', 'tour_booking_id', 'driver_id',
     'guide_id',
    'sold_tour_id'];

    // public function tour_booking(): BelongsTo
    // {
    //     return $this->belongsTo(TourBooking::class);
    // }

    public function sold_tour(): BelongsTo
    {
        return $this->belongsTo(SoldTour::class);
    }
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function guide(): BelongsTo
    {
        return $this->belongsTo(Guide::class);
    }


}
