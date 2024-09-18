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
        'special_request'
    ];


    public function tourRepeaterDrivers(): HasMany
    {
        return $this->hasMany(TourRepeaterDriver::class);
    }

    public function tourRepeaterGuides(): HasMany
    {
        return $this->hasMany(TourRepeaterGuide::class);
    }

    public function tour(): BelongsTo
    {
        return $this->belongsTo(Tour::class);
    }

    
}

