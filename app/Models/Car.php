<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Car extends Model
{
    use HasFactory;
    protected $fillable = [ 
        'plate_number',
         'brand_name',
          'image',
           'car_brand_id',
           'driver_id',
           'color'
    ];

    // public function drivers(): BelongsToMany
    // {
    //     return $this->belongsToMany(Driver::class, 'car_driver');
    // }


//     public function drivers()
// {
//     return $this->belongsToMany(Driver::class, 'car_driver', 'car_id', 'driver_id')
//                 ->withPivot('car_plate'); // Assuming 'car_plate' is in the pivot table
// }
 public function carBrand(): BelongsTo {
    return $this->belongsTo(CarBrand::class);
 }

 public function driver(): BelongsTo {
    return $this->belongsTo(Driver::class);
 }


}
 