<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoomStatus extends Model
{
    protected $fillable = ['room_number', 'status', 'cleaned_by', 'cleaned_at'];

    protected $casts = ['cleaned_at' => 'datetime'];

    public function cleaner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cleaned_by');
    }

    public function markClean(int $userId): void
    {
        $this->update([
            'status'     => 'clean',
            'cleaned_by' => $userId,
            'cleaned_at' => now(),
        ]);

        RoomCleaning::create([
            'room_number' => $this->room_number,
            'cleaned_by'  => $userId,
            'cleaned_at'  => now(),
        ]);
    }

    public function markDirty(): void
    {
        $this->update([
            'status'     => 'dirty',
            'cleaned_by' => null,
            'cleaned_at' => null,
        ]);
    }
}
