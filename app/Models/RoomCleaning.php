<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoomCleaning extends Model
{
    protected $fillable = ['room_number', 'cleaned_by', 'cleaned_at'];

    protected $casts = ['cleaned_at' => 'datetime'];

    public function cleaner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cleaned_by');
    }
}
