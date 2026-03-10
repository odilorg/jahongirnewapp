<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Beds24Booking extends Model
{
    /**
     * This table is append-only. Do not add SoftDeletes.
     * Cancellations are tracked via booking_status = 'cancelled' and cancelled_at timestamp.
     */

    protected $fillable = [
        'beds24_booking_id',
        'property_id',
        'room_id',
        'room_name',
        'guest_name',
        'guest_email',
        'guest_phone',
        'channel',
        'arrival_date',
        'departure_date',
        'num_adults',
        'num_children',
        'total_amount',
        'currency',
        'payment_status',
        'payment_type',
        'booking_status',
        'original_status',
        'invoice_balance',
        'beds24_raw_data',
        'admin_confirmed_at',
        'admin_id',
        'notes',
        'cancelled_at',
    ];

    protected $casts = [
        'total_amount'       => 'decimal:2',
        'invoice_balance'    => 'decimal:2',
        'num_adults'         => 'integer',
        'num_children'       => 'integer',
        'arrival_date'       => 'date',
        'departure_date'     => 'date',
        'beds24_raw_data'    => 'array',
        'admin_confirmed_at' => 'datetime',
        'cancelled_at'       => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * Admin who confirmed payment
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    /**
     * All change/audit records for this booking
     */
    public function changes(): HasMany
    {
        return $this->hasMany(Beds24BookingChange::class, 'beds24_booking_id', 'beds24_booking_id');
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeConfirmed($query)
    {
        return $query->where('booking_status', 'confirmed');
    }

    public function scopeCancelled($query)
    {
        return $query->where('booking_status', 'cancelled');
    }

    public function scopeForProperty($query, string $propertyId)
    {
        return $query->where('property_id', $propertyId);
    }

    public function scopeArrivingBetween($query, string $from, string $to)
    {
        return $query->whereBetween('arrival_date', [$from, $to]);
    }

    public function scopeWithUnpaidBalance($query)
    {
        return $query->where('invoice_balance', '>', 0);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public function isConfirmed(): bool
    {
        return $this->booking_status === 'confirmed';
    }

    public function isCancelled(): bool
    {
        return $this->booking_status === 'cancelled';
    }

    public function isPaid(): bool
    {
        return $this->payment_status === 'paid';
    }

    public function getPropertyName(): string
    {
        return match ((string) $this->property_id) {
            '41097'  => 'Jahongir Hotel',
            '172793' => 'Jahongir Premium',
            default  => 'Property ' . $this->property_id,
        };
    }

    /**
     * Number of nights for the stay
     */
    public function getNightsAttribute(): int
    {
        if (!$this->arrival_date || !$this->departure_date) {
            return 0;
        }

        return (int) $this->arrival_date->diffInDays($this->departure_date);
    }
}
