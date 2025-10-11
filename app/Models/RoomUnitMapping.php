<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoomUnitMapping extends Model
{
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
}
