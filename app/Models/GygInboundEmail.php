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

    // ── Relationships (Phase 5) ─────────────────────

    public function booking()
    {
        return $this->belongsTo(\App\Models\Booking::class ?? Model::class);
    }
}
