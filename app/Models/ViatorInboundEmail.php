<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable event log of inbound Viator booking emails.
 *
 * One row per email — never updated except for processing_status /
 * processed_at / error_message / booking_inquiry_id transitions.
 * The raw_body and parsed_payload are write-once and treated as
 * the source of truth if the parser ever needs to be re-run.
 *
 * Idempotency: gmail_message_id is the IMAP Message-ID header. The
 * fetch command relies on the unique index to skip already-stored
 * mail without doing per-row existence checks.
 *
 * Domain operations (auto-apply, flag for review, etc.) live in
 * dedicated services — this model is intentionally thin.
 */
class ViatorInboundEmail extends Model
{
    public const TYPE_NEW       = 'new';
    public const TYPE_AMENDED   = 'amended';
    public const TYPE_CANCELLED = 'cancelled';
    public const TYPE_UNKNOWN   = 'unknown';

    public const STATUS_FETCHED      = 'fetched';
    public const STATUS_PARSED       = 'parsed';
    public const STATUS_APPLIED      = 'applied';
    public const STATUS_NEEDS_REVIEW = 'needs_review';
    public const STATUS_FAILED       = 'failed';

    protected $fillable = [
        'gmail_message_id',
        'from_address',
        'subject_raw',
        'email_type',
        'external_reference',
        'raw_body',
        'parsed_payload',
        'parsed_diff',
        'processing_status',
        'processed_at',
        'error_message',
        'booking_inquiry_id',
    ];

    protected $casts = [
        'parsed_payload' => 'array',
        'parsed_diff'    => 'array',
        'processed_at'   => 'datetime',
    ];

    public function bookingInquiry(): BelongsTo
    {
        return $this->belongsTo(BookingInquiry::class);
    }

    public function isAutoApplicable(): bool
    {
        return $this->email_type === self::TYPE_NEW
            && $this->processing_status === self::STATUS_PARSED;
    }

    public function needsOperatorReview(): bool
    {
        return $this->processing_status === self::STATUS_NEEDS_REVIEW;
    }
}
