<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class RoomType extends Model
{
    use HasFactory;

    protected $casts = [
        'price_as_double' => MoneyCast::class,
        'price_as_single' => MoneyCast::class,
    ];

    protected $fillable = [
        'name',
        'description',
       
        'price_as_double',
        'price_as_single',
       
    ];

    public function rooms()
    {
        return $this->hasMany(Room::class);
    }
}
