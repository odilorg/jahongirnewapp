<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GygInboundEmail extends Model
{
    protected $table = 'gyg_inbound_emails';

    protected $guarded = ['id'];

    protected $casts = [
        'email_date'    => 'datetime',
        'travel_date'   => 'date',
        'classified_at' => 'datetime',
        'parsed_at'     => 'datetime',
        'applied_at'    => 'datetime',
        'notified_at'   => 'datetime',
        'price'         => 'decimal:2',
        'pax'           => 'integer',
    ];

    // ── Status helpers ──────────────────────────────

    public function isPending(): bool
    {
        return $this->processing_status === 'fetched';
    }

    public function needsReview(): bool
    {
        return $this->processing_status === 'needs_review';
    }

    public function isApplied(): bool
    {
        return $this->processing_status === 'applied';
    }

    // ── Relationships ───────────────────────────────

    /** Legacy link — points to the dead `bookings` table. Kept for audit. */
    public function booking()
    {
        return $this->belongsTo(\App\Models\Booking::class);
    }

    /** Live link — points to the booking_inquiries table (Phase 8.5). */
    public function bookingInquiry()
    {
        return $this->belongsTo(BookingInquiry::class);
    }
}
