<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

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
        'hotel_id',
        'price',
        'price_as_single',
        'room_type_id'
    ];

    protected $casts = [
        'price' => MoneyCast::class,
        'price_as_single' => MoneyCast::class,
    ];

    public function amenities()
{
    return $this->belongsToMany(Amenity::class);
}

public function hotel(): BelongsTo
{
    return $this->belongsTo(Hotel::class);
}

public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }


}
