<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoomStatus extends Model
{
    protected $fillable = [
        'beds24_property_id',
        'beds24_room_id',
        'room_name',
        'unit_number',
        'unit_name',
        'status',
        'updated_by',
        'notes',
    ];

    protected $casts = [
        'beds24_property_id' => 'integer',
        'beds24_room_id'     => 'integer',
        'unit_number'        => 'integer',
        'updated_by'         => 'integer',
    ];

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function statusEmoji(): string
    {
        return match($this->status) {
            'clean'  => '✅',
            'dirty'  => '🟡',
            'repair' => '🔴',
            default  => '❓',
        };
    }

    public function statusLabel(): string
    {
        return match($this->status) {
            'clean'  => 'Чистый',
            'dirty'  => 'Грязный',
            'repair' => 'Ремонт',
            default  => 'Неизвестно',
        };
    }

    public function getPropertyName(): string
    {
        return match($this->beds24_property_id) {
            41097  => 'Jahongir Hotel',
            172793 => 'Jahongir Premium',
            default => 'Неизвестный объект',
        };
    }
}
