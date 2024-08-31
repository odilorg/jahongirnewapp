<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Car extends Model
{
    use HasFactory;
    protected $fillable = [ 'model', 'number_seats', 'number_luggage', 'image'];

    // public function drivers(): BelongsToMany
    // {
    //     return $this->belongsToMany(Driver::class, 'car_driver');
    // }






}
 