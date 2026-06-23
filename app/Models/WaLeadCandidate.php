<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A WhatsApp inbound prospect surfaced for operator review. Thin model — dedup
 * and upsert live in App\Actions\WhatsApp\IngestWaCandidates; the operator's
 * confirm/dismiss decision lives in the review UI (Phase 2).
 */
class WaLeadCandidate extends Model
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_CREATED   = 'created';
    public const STATUS_DISMISSED = 'dismissed';

    protected $fillable = [
        'phone', 'first_inbound', 'last_inbound_at',
        'inbound_count', 'outbound_count', 'status',
        'booking_inquiry_id', 'decided_by', 'decided_at',
    ];

    protected $casts = [
        'last_inbound_at' => 'datetime',
        'decided_at'      => 'datetime',
        'inbound_count'   => 'integer',
        'outbound_count'  => 'integer',
    ];

    public function bookingInquiry(): BelongsTo
    {
        return $this->belongsTo(BookingInquiry::class);
    }
}
