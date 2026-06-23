<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable per-message ledger for Gmail -> CRM lead ingestion. One row per
 * inspected message; `gmail_message_id` is the idempotency key. Thin model —
 * decision logic lives in App\Actions\BookingInquiries\IngestGmailEmailAsInquiry.
 */
class GmailLeadIngestion extends Model
{
    public const STATUS_CREATED                  = 'created';
    public const STATUS_SKIPPED_DUPLICATE_INQUIRY = 'skipped_duplicate_inquiry';
    public const STATUS_SKIPPED_NOT_A_LEAD       = 'skipped_not_a_lead';
    public const STATUS_SKIPPED_NO_GUEST_EMAIL   = 'skipped_no_guest_email';
    public const STATUS_SKIPPED_BLOCKLIST        = 'skipped_blocklist';
    public const STATUS_FAILED                   = 'failed';

    protected $fillable = [
        'provider',
        'gmail_message_id',
        'envelope_id',
        'kind',
        'status',
        'booking_inquiry_id',
        'sender_email',
        'guest_email',
        'subject',
        'has_attachments',
        'error_message',
        'processed_at',
    ];

    protected $casts = [
        'has_attachments' => 'boolean',
        'processed_at'    => 'datetime',
    ];

    public function bookingInquiry(): BelongsTo
    {
        return $this->belongsTo(BookingInquiry::class);
    }
}
