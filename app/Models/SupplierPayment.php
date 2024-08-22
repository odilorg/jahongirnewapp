<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierPayment extends Model
{
    use HasFactory;
    protected $fillable = ['payment_date', 'amount_paid', 'tour_booking_id', 'driver_id', 'guide_id'];

    public function tour_booking(): BelongsTo
    {
        return $this->belongsTo(TourBooking::class);
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
