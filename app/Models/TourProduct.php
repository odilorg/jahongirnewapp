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
        'pdf_enabled',
        'pdf_relative_path',
        'sort_order',
        'source_type',
        'source_path',
        'import_hash',
        'last_imported_at',
    ];

    protected $casts = [
        'highlights'        => 'array',
        'is_active'         => 'boolean',
        'pdf_enabled'       => 'boolean',
        'starting_from_usd' => 'decimal:2',
        'last_imported_at'  => 'datetime',
    ];

    public function priceTiers(): HasMany
    {
        return $this->hasMany(TourPriceTier::class)->orderBy('group_size');
    }

    public function directions(): HasMany
    {
        return $this->hasMany(TourProductDirection::class)->orderBy('sort_order');
    }

    public function bookingInquiries(): HasMany
    {
        // Phase 8.2 will add tour_product_id FK on booking_inquiries
        // and wire this relation; safe to declare it here now.
        return $this->hasMany(BookingInquiry::class);
    }

    /**
     * Find the best-matching price tier for (pax, direction, type).
     *
     * Resolution order:
     *   1. Direction-specific tier with exact group_size for the given type
     *   2. Global (direction=null) tier with exact group_size for the given type
     *   3. Direction-specific tier with largest group_size ≤ pax
     *   4. Global tier with largest group_size ≤ pax
     *
     * Direction-specific tiers always beat global tiers at the same
     * level. Pricing is generous on group size (a 5-person group
     * qualifies for the 4-person tier rate if 5 isn't listed).
     */
    public function priceFor(int $groupSize, ?string $directionCode = null, string $type = self::TYPE_PRIVATE): ?TourPriceTier
    {
        if ($groupSize < 1) {
            return null;
        }

        $directionId = null;
        if ($directionCode !== null) {
            $directionId = $this->directions
                ->where('is_active', true)
                ->firstWhere('code', $directionCode)?->id;
        }

        $candidates = $this->priceTiers
            ->where('is_active', true)
            ->where('tour_type', $type)
            ->filter(function (TourPriceTier $tier) use ($directionId): bool {
                // Match this direction, or global (null) if no direction
                // was asked for OR as a fallback.
                return $tier->tour_product_direction_id === $directionId
                    || $tier->tour_product_direction_id === null;
            });

        $pick = function ($collection) use ($directionId) {
            // Prefer direction-specific (non-null) over global (null).
            return $collection->sortByDesc(
                fn (TourPriceTier $t) => $t->tour_product_direction_id === $directionId ? 1 : 0
            )->first();
        };

        // 1/2: exact match
        $exact = $candidates->where('group_size', $groupSize);
        if ($exact->isNotEmpty()) {
            return $pick($exact);
        }

        // 3/4: largest tier ≤ pax
        $fallback = $candidates
            ->where('group_size', '<=', $groupSize)
            ->sortByDesc('group_size');

        return $pick($fallback);
    }

    /**
     * Recompute starting_from_usd from the cheapest active tier and
     * persist it. Called by the TourPriceTier observer whenever tiers
     * are saved or deleted, so list-view "from $X" stays accurate
     * without per-row queries.
     */
    public function recalculateStartingPrice(): void
    {
        // Use min() aggregate rather than orderBy + value() because the
        // priceTiers() relation has a built-in orderBy('group_size') that
        // would clobber an explicit price order and return the cheapest
        // by group size instead of by price.
        $cheapest = $this->priceTiers()
            ->where('is_active', true)
            ->min('price_per_person_usd');

        $this->forceFill(['starting_from_usd' => $cheapest])->saveQuietly();
    }

    public static function suggestSlug(string $title): string
    {
        return Str::slug($title);
    }
}
