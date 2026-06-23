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
    public const STATUS_REVIEW    = 'review';
    public const STATUS_CREATED   = 'created';
    public const STATUS_DISMISSED = 'dismissed';

    public const CLASS_GENUINE   = 'genuine_tour_inquiry';
    public const CLASS_NOT_LEAD  = 'not_lead';
    public const CLASS_UNCERTAIN = 'uncertain';

    protected $fillable = [
        'phone', 'first_inbound', 'first_messages', 'last_inbound_at',
        'inbound_count', 'outbound_count', 'status',
        'classification', 'not_lead_subtype', 'confidence', 'reason',
        'detected_tour', 'detected_date', 'detected_party_size', 'language',
        'needs_review', 'decision', 'dismissed_reason', 'classified_at',
        'booking_inquiry_id', 'decided_by', 'decided_at',
    ];

    protected $casts = [
        'last_inbound_at' => 'datetime',
        'classified_at'   => 'datetime',
        'decided_at'      => 'datetime',
        'detected_date'   => 'date',
        'inbound_count'   => 'integer',
        'outbound_count'  => 'integer',
        'detected_party_size' => 'integer',
        'confidence'      => 'float',
        'needs_review'    => 'boolean',
    ];

    public function bookingInquiry(): BelongsTo
    {
        return $this->belongsTo(BookingInquiry::class);
    }
}
