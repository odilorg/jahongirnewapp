<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TourRepeaterDriver extends Model
{
    use HasFactory;

    protected $fillable = [
        'driver_id',
        'amount_paid',
        'sold_tour_id',
        'payment_date',
        'payment_document_image',
        'payment_method',
        

    ];
    protected $casts = [
        'amount_paid' => MoneyCast::class,
    ];
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    
}
