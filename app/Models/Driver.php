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
    protected $fillable = ['address_city', 'extra_details', 'car_id', 'first_name', 'last_name', 'email', 'phone01', 'phone02', 'fuel_type', 'driver_image', 'telegram_chat_id', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function scopeActive(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_active', true);
    }


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

   public function averageScore(): ?float
{
    return $this->ratings()->avg('score');
}

public function totalRatings(): int
{
    return $this->ratings()->count();
}
    

    public function ratings(): HasMany
    {
        return $this->hasMany(Rating::class);
    }

    public function tourExpenses()
    {
        return $this->morphMany(TourExpense::class, 'supplier');
    }

    public function rates(): HasMany
    {
        return $this->hasMany(DriverRate::class)->orderBy('sort_order')->orderBy('label');
    }

    public function supplierPayments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class, 'supplier_id')
            ->where('supplier_type', 'driver');
    }

    public function payments()
    {
        return SupplierPayment::forSupplier('driver', $this->id)->recorded();
    }

    public function totalOwed(): float
    {
        return (float) BookingInquiry::where('driver_id', $this->id)
            ->whereNotNull('driver_cost')
            ->sum('driver_cost');
    }

    public function totalPaid(): float
    {
        return (float) $this->payments()->sum('amount');
    }

    public function outstandingBalance(): float
    {
        return $this->totalOwed() - $this->totalPaid();
    }
}
