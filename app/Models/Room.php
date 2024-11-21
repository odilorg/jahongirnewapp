<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Room extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'quantity',
        'room_number',
        'room_floor',
        'notes',
        'hotel_id'
    ];

    public function amenities()
{
    return $this->belongsToMany(Amenity::class);
}

public function hotel(): BelongsTo
{
    return $this->belongsTo(Hotel::class);
}
}
