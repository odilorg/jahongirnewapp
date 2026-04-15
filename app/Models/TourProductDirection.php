<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Route variant of a tour product.
 *
 * Same base product, different itinerary. Yurt Camp Tour has three:
 *   sam-bukhara   — Samarkand → Bukhara (default)
 *   sam-sam       — Samarkand loop (back to Samarkand)
 *   bukhara-sam   — Bukhara → Samarkand (reverse)
 *
 * Directions affect content/wording only. Pricing can vary by
 * direction (route length, transport cost) via price tiers that are
 * scoped to a specific direction.
 */
class TourProductDirection extends Model
{
    use HasFactory;

    protected $fillable = [
        'tour_product_id',
        'code',
        'name',
        'start_city',
        'end_city',
        'notes',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    public function tourProduct(): BelongsTo
    {
        return $this->belongsTo(TourProduct::class);
    }

    public function priceTiers(): HasMany
    {
        return $this->hasMany(TourPriceTier::class);
    }
}
