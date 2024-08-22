<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Driver extends Model
{
    use HasFactory;
    protected $fillable = ['car_id', 'first_name', 'last_name', 'email', 'phone01', 'phone02', 'fuel_type', 'driver_image'];

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }

    public function supplier_payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }

}
