<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GygReservation extends Model
{
    protected $table = 'gyg_reservations';

    protected $fillable = [
        'reservation_reference',
        'gyg_booking_reference',
        'gyg_product_id',
        'slot_datetime',
        'booking_items',
        'currency',
        'status',
        'expires_at',
    ];

    protected $casts = [
        'slot_datetime' => 'datetime',
        'expires_at' => 'datetime',
        'booking_items' => 'array',
    ];

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && ! $this->isExpired();
    }
}
