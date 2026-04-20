<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class RoomUnitMapping extends Model
{
    /**
     * Beds24 property IDs for Jahongir's two properties. Referenced by the
     * Telegram booking bot's NLP parser when the staff member says "at
     * Premium" / "at Hotel" to disambiguate a unit number that exists in
     * both properties.
     */
    private const PROPERTY_ID_PREMIUM = '172793';
    private const PROPERTY_ID_HOTEL   = '41097';

    protected $fillable = [
        'unit_name',
        'property_id',
        'property_name',
        'room_id',
        'room_name',
        'room_type',
        'max_guests',
        'base_price',
    ];

    protected $casts = [
        'max_guests' => 'integer',
        'base_price' => 'decimal:2',
    ];

    // Find room by unit name (e.g., "12", "22")
    public static function findByUnitName(string $unitName): ?self
    {
        return self::where('unit_name', $unitName)->first();
    }

    // Search rooms (fuzzy match)
    public static function search(string $query): \Illuminate\Database\Eloquent\Collection
    {
        return self::where('unit_name', 'like', "%{$query}%")
            ->orWhere('room_name', 'like', "%{$query}%")
            ->orWhere('room_type', 'like', "%{$query}%")
            ->get();
    }

    public function scopeForUnit(Builder $query, string $unitName): Builder
    {
        return $query->where('unit_name', $unitName);
    }

    /**
     * Narrow to a property when the parser emits a free-text hint like
     * "premium" / "hotel". Anything else (null, unknown word) is a no-op —
     * keeping both properties in play so the caller can surface the
     * "multiple rooms match" disambiguation prompt.
     */
    public function scopeMatchingPropertyHint(Builder $query, ?string $propertyHint): Builder
    {
        if ($propertyHint === null) {
            return $query;
        }

        if (stripos($propertyHint, 'premium') !== false) {
            return $query->where('property_id', self::PROPERTY_ID_PREMIUM);
        }

        if (stripos($propertyHint, 'hotel') !== false) {
            return $query->where('property_id', self::PROPERTY_ID_HOTEL);
        }

        return $query;
    }
}
