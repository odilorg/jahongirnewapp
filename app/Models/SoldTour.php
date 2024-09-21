<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SoldTour extends Model
{
    use HasFactory;

    protected $fillable = [
        'tour_id',
        'pickup_location',
        'dropoff_location',
        'special_request',
        'driver_id',
        'guide_id'

    ];


    // public function tourRepeaterDrivers(): HasMany
    // {
    //     return $this->hasMany(TourRepeaterDriver::class);
    // }
    public function tourRepeaterDrivers()
    {
        return $this->belongsToMany(Driver::class, 'tour_repeater_drivers')
            ->withPivot('amount_paid', 'payment_date', 'payment_method', 'payment_document_image');
    }
    // public function tourRepeaterGuides(): HasMany
    // {
    //     return $this->hasMany(TourRepeaterGuide::class);
    // }

    public function tourRepeaterGuides()
    {
        return $this->belongsToMany(Guide::class, 'tour_repeater_guides')
            ->withPivot('amount_paid', 'payment_date', 'payment_method', 'payment_document_image');
    }

    public function tour(): BelongsTo
    {
        return $this->belongsTo(Tour::class);
    }
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function guide(): BelongsTo
    {
        return $this->belongsTo(related: Guide::class);
    }


    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }
    
}

