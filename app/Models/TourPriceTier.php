<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-group-size selling price for a tour product.
 *
 * Booted to keep the parent's cached `starting_from_usd` accurate:
 * any save / delete recalculates the cheapest active tier and writes
 * it back to the parent, so list views and the "starting from"
 * label stay correct without per-row queries.
 */
class TourPriceTier extends Model
{
    use HasFactory;

    protected $fillable = [
        'tour_product_id',
        'tour_product_direction_id',
        'tour_type',
        'group_size',
        'price_per_person_usd',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'is_active'            => 'boolean',
        'group_size'           => 'integer',
        'price_per_person_usd' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::saved(function (TourPriceTier $tier): void {
            $tier->tourProduct?->recalculateStartingPrice();
        });

        static::deleted(function (TourPriceTier $tier): void {
            $tier->tourProduct?->recalculateStartingPrice();
        });
    }

    public function tourProduct(): BelongsTo
    {
        return $this->belongsTo(TourProduct::class);
    }

    public function direction(): BelongsTo
    {
        return $this->belongsTo(TourProductDirection::class, 'tour_product_direction_id');
    }

    public function totalForGroup(): float
    {
        return (float) $this->price_per_person_usd * $this->group_size;
    }
}
