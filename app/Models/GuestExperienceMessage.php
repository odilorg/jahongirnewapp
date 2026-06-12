<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single timed guest-care touchpoint for one booking (Phase 29).
 *
 * Rows are pre-materialized when a booking is confirmed; the send command
 * scans `status = pending AND due_at <= now`. State machine mirrors the
 * 24h reminder vocabulary:
 *   pending → sending → sent | failed | unknown | skipped | suppressed
 */
class GuestExperienceMessage extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENDING = 'sending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_UNKNOWN = 'unknown';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_SUPPRESSED = 'suppressed';

    protected $fillable = [
        'booking_inquiry_id',
        'message_type',
        'channel',
        'status',
        'due_at',
        'sent_at',
        'attempt_count',
        'last_attempted_at',
        'last_error',
        'idempotency_key',
        'reply_received',
        'meta',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'sent_at' => 'datetime',
        'last_attempted_at' => 'datetime',
        'attempt_count' => 'integer',
        'reply_received' => 'boolean',
        'meta' => 'array',
    ];

    public function bookingInquiry(): BelongsTo
    {
        return $this->belongsTo(BookingInquiry::class);
    }
}
