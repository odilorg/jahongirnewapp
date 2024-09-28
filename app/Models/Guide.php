<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Guide extends Model
{
    use HasFactory;

    protected $casts = [
        'lang_spoken' => 'array',
    ];

    protected $fillable = ['first_name', 'last_name', 'email', 'phone01', 'phone02', 'lang_spoken', 'guide_image'];

    public function languages(): BelongsToMany
    {
        return $this->belongsToMany(SpokenLanguage::class, 'language_guide');
    }

    // public function soldTours()
    // {
    //     return $this->belongsToMany(SoldTour::class, 'sold_tour_guide')
    //                 ->withPivot('amount_paid', 'payment_date', 'payment_method')
    //                 ->withTimestamps();
    // }

    public function supplier_payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }
}
