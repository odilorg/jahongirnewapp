<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Tour catalog entry — what we sell, not how it's run internally.
 *
 * Represents the public-facing product (display copy, hero image,
 * selling price tiers). Internal supplier costing (driver day rates,
 * accommodation rate cards, guide rates) lives on the supplier
 * models and stays separate from this on purpose: sales catalog vs
 * internal costing.
 */
class TourProduct extends Model
{
    use HasFactory;

    public const TYPE_PRIVATE = 'private';
    public const TYPE_GROUP   = 'group';

    public const TYPES = [self::TYPE_PRIVATE, self::TYPE_GROUP];

    public const REGIONS = [
        'samarkand'  => 'Samarkand',
        'bukhara'    => 'Bukhara',
        'khiva'      => 'Khiva',
        'tashkent'   => 'Tashkent',
        'nuratau'    => 'Nuratau',
        'tajikistan' => 'Tajikistan',
        'uzbekistan' => 'Uzbekistan (multi-city)',
    ];

    protected $fillable = [
        'slug',
        'title',
        'region',
        'tour_type',
        'duration_days',
        'duration_nights',
        'starting_from_usd',
        'currency',
        'description',
        'highlights',
        'includes',
        'excludes',
        'hero_image_url',
        'page_url',
        'meta_description',
        'is_active',
        'sort_order',
        'source_type',
        'source_path',
        'import_hash',
        'last_imported_at',
    ];

    protected $casts = [
        'highlights'        => 'array',
        'is_active'         => 'boolean',
        'starting_from_usd' => 'decimal:2',
        'last_imported_at'  => 'datetime',
    ];

    public function priceTiers(): HasMany
    {
        return $this->hasMany(TourPriceTier::class)->orderBy('group_size');
    }

    public function bookingInquiries(): HasMany
    {
        // Phase 8.2 will add tour_product_id FK on booking_inquiries
        // and wire this relation; safe to declare it here now.
        return $this->hasMany(BookingInquiry::class);
    }

    /**
     * Find the price-per-person for the given group size.
     * Falls back to the next-larger tier when no exact match exists,
     * because pricing is usually generous on group size (a 5-person
     * group can usually get a 4-person tier price if 5 isn't listed).
     */
    public function priceFor(int $groupSize): ?TourPriceTier
    {
        if ($groupSize < 1) {
            return null;
        }

        // Exact match first.
        $exact = $this->priceTiers
            ->where('is_active', true)
            ->firstWhere('group_size', $groupSize);

        if ($exact) {
            return $exact;
        }

        // Fallback: largest tier <= group size (usually the cheapest
        // tier the group qualifies for).
        return $this->priceTiers
            ->where('is_active', true)
            ->where('group_size', '<=', $groupSize)
            ->sortByDesc('group_size')
            ->first();
    }

    /**
     * Recompute starting_from_usd from the cheapest active tier and
     * persist it. Called by the TourPriceTier observer whenever tiers
     * are saved or deleted, so list-view "from $X" stays accurate
     * without per-row queries.
     */
    public function recalculateStartingPrice(): void
    {
        $cheapest = $this->priceTiers()
            ->where('is_active', true)
            ->orderBy('price_per_person_usd')
            ->value('price_per_person_usd');

        $this->forceFill(['starting_from_usd' => $cheapest])->saveQuietly();
    }

    public static function suggestSlug(string $title): string
    {
        return Str::slug($title);
    }
}
