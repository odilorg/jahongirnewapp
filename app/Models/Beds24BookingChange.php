<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Beds24BookingChange extends Model
{
    protected $fillable = [
        'beds24_booking_id',
        'change_type',
        'old_data',
        'new_data',
        'detected_at',
        'alerted_at',
    ];

    protected $casts = [
        'old_data'    => 'array',
        'new_data'    => 'array',
        'detected_at' => 'datetime',
        'alerted_at'  => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * The booking this change belongs to (string FK for resilience)
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Beds24Booking::class, 'beds24_booking_id', 'beds24_booking_id');
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeUnalerted($query)
    {
        return $query->whereNull('alerted_at');
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('change_type', $type);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Mark this change as alerted to the owner
     */
    public function markAlerted(): void
    {
        $this->update(['alerted_at' => now()]);
    }

    public function isCritical(): bool
    {
        return in_array($this->change_type, ['cancelled', 'amount_changed']);
    }
}
