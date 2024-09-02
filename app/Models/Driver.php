<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Driver extends Model
{
    use HasFactory;
    protected $fillable = ['address_city', 'extra_details', 'car_id', 'first_name', 'last_name', 'email', 'phone01', 'phone02', 'fuel_type', 'driver_image'];

    
    public function carsplates(): HasMany
    {
        return $this->hasMany(CarDriver::class);
    }

    // public function cars(): BelongsToMany
    // {
    //     return $this->belongsToMany(Car::class);
    // }
    public function cars()
    {
        return $this->belongsToMany(Car::class, 'car_driver', 'driver_id', 'car_id')
                    ->withPivot('car_plate'); // Assume 'car_plate' is stored in the pivot table
    }
    

    public function ratings(): HasMany
    {
        return $this->hasMany(Rating::class);
    }

    public function supplier_payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }

}
