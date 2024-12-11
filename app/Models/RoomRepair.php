<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class RoomRepair extends Model
{
    use HasFactory;

    protected $fillable = [
        'repair_date',
        'room_id',
        'room_number',
        'repair_name',
        'amount',
        'notes'
        ];

       

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

}
