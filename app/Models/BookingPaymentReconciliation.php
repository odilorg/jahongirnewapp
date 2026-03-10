<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingPaymentReconciliation extends Model
{
    protected $fillable = [
        'beds24_booking_id', 'property_id', 'expected_amount', 'reported_amount',
        'discrepancy_amount', 'currency', 'status', 'flagged_at',
        'resolved_by', 'resolved_at', 'resolution_notes',
    ];

    protected $casts = [
        'expected_amount' => 'decimal:2', 'reported_amount' => 'decimal:2',
        'discrepancy_amount' => 'decimal:2', 'flagged_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function booking() { return $this->belongsTo(Beds24Booking::class, 'beds24_booking_id', 'beds24_booking_id'); }
    public function resolver() { return $this->belongsTo(User::class, 'resolved_by'); }
}
