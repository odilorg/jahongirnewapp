<?php

namespace App\Models;

use App\Models\Car;
use App\Models\Rating;
use App\Models\CarDriver;
use App\Models\SupplierPayment;
use App\Models\TourRepeaterDriver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class Driver extends Model
{
    use HasFactory;
    protected $fillable = ['address_city', 'extra_details', 'car_id', 'first_name', 'last_name', 'email', 'phone01', 'phone02', 'fuel_type', 'driver_image'];

    
    // public function carsplates(): HasMany
    // {
    //     return $this->hasMany(CarDriver::class);
    // }
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
    public function cars(): HasMany
    {
        return $this->hasMany(Car::class);
    }

    // public function driverPayments(): HasMany
    // {
    //     return $this->hasMany(DriverPayment::class);
    // }

    // public function sold_tours(): HasMany
    // {
    //     return $this->hasMany(SoldTour::class);
    // }


    // public function cars(): BelongsToMany
    // {
    //     return $this->belongsToMany(Car::class);
    // }
    // public function cars()
    // {
    //     return $this->belongsToMany(Car::class, 'car_driver', 'driver_id', 'car_id')
    //                 ->withPivot('car_plate'); // Assume 'car_plate' is stored in the pivot table
    // }

    // public function payments()
    // {
    //     return $this->belongsToMany(SoldTour::class, 'driver_payment', 'driver_id', 'sold_tour_id')
    //                 ->withPivot('amount_paid', 'payment_date', 'receipt_image', 'payment_type'); // Assume 'car_plate' is stored in the pivot table
    // }
    

    public function ratings(): HasMany
    {
        return $this->hasMany(Rating::class);
    }

   

    // public function tour_repeater_driver(): HasOne
    // {
    //     return $this->hasOne(TourRepeaterDriver::class);
    // }

//     public function soldTours()
//     {
//         return $this->belongsToMany(SoldTour::class, 'sold_tour_driver')
//                     ->withPivot('amount_paid', 'payment_date', 'payment_method')
//                     ->withTimestamps();
//     }
//     public function tourRepeaterDrivers()
//     {
//         return $this->hasMany(TourRepeaterDriver::class);
//     }
//     public function tours()
//     {
//         return $this->hasManyThrough(
//             Tour::class,
//             SoldTour::class,
//             'id', // Foreign key on the SoldTour table
//             'id', // Foreign key on the Tour table
//             'id', // Local key on the Driver table
//             'tour_id' // Local key on the SoldTour table
//         );
//     }

//     public function getTotalAmountPaidAttribute()
// {
//     return $this->soldTours()->sum('amount_paid');
// }
    
}
