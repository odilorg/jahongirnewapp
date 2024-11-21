<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Roomprice extends Model
{
    use HasFactory;

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    protected $casts = [
        'price' => MoneyCast::class,
    ];

    protected $fillable = [
        'name',
        'price',
       'room_id'
    ];
}
