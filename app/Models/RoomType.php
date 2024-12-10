<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class RoomType extends Model
{
    use HasFactory;

    protected $casts = [
        'price_as_double' => MoneyCast::class,
        'price_as_single' => MoneyCast::class,
    ];

    protected $fillable = [
        'name',
        'description',
       
        'price_as_double',
        'price_as_single',
        'quantity',
        'number_of_beds',
        'hotel_id',
        'created_by',
        'updated_by',
       
    ];

    public function rooms()
    {
        return $this->hasMany(Room::class);
    }

    public function getDiscountedPriceAsSingleForHotelAttribute()
    {
        $discount = config('app.winter_discount', 0);
    
        if ($this->hotel) {
            return [
                'hotel_1' => $this->calculateDiscountedPrice($this->price_as_single, $discount, 1),
                'hotel_2' => $this->calculateDiscountedPrice($this->price_as_single, $discount, 2),
            ];
        }
    
        return null; // Handle cases where the hotel is not loaded
    }
    
    public function getDiscountedPriceAsDoubleForHotelAttribute()
    {
        $discount = config('app.winter_discount', 0);
    
        if ($this->hotel) {
            return [
                'hotel_1' => $this->calculateDiscountedPrice($this->price_as_double, $discount, 1),
                'hotel_2' => $this->calculateDiscountedPrice($this->price_as_double, $discount, 2),
            ];
        }
    
        return null; // Handle cases where the hotel is not loaded
    }
    
    /**
     * Helper to calculate the discounted price for a specific hotel.
     */
    protected function calculateDiscountedPrice($price, $discount, $hotelId)
    {
        if ($this->hotel->id === $hotelId) {
            return $price - ($price * ($discount / 100));
        }
    
        return $price; // No discount if hotel does not match
    }

public function hotel(): BelongsTo
{
    return $this->belongsTo(Hotel::class);
}

 // Automatically set `created_by` and `updated_by`
 protected static function boot()
 {
     parent::boot();

     static::creating(function ($model) {
         $model->created_by = auth()->id();
     });

     static::updating(function ($model) {
         $model->updated_by = auth()->id();
     });
 }


}
