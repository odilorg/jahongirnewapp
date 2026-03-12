<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LostFoundItem extends Model
{
    protected $fillable = [
        'room_number',
        'found_by',
        'photo_path',
        'telegram_file_id',
        'description',
        'status',
        'claimed_by_guest',
        'claimed_at',
    ];

    protected $casts = [
        'claimed_at' => 'datetime',
    ];

    public function finder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'found_by');
    }
}
