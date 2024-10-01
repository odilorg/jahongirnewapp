<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Tour extends Model
{
    use HasFactory;
    protected $fillable = ['title', 'tour_duration', 'tour_description', 'tour_price'];

    protected $casts = [
        'tour_price' => MoneyCast::class,
    ];

    public function tourPrices(): HasMany
    {
        return $this->hasMany(TourPrice::class);
    }
}
