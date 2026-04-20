<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit + dedupe table for inbound email → lead ingestion.
 *
 * One row per fetched message, regardless of outcome. The
 * (provider, remote_message_id) unique constraint is the dedupe guarantee;
 * re-running the fetch command cannot double-ingest even if Zoho's unread
 * flags get out of sync.
 */
class LeadEmailIngestion extends Model
{
    use HasFactory;

    public const STATUS_PROCESSED         = 'processed';
    public const STATUS_AMBIGUOUS         = 'ambiguous';
    public const STATUS_SKIPPED_BLOCKLIST = 'skipped_blocklist';
    public const STATUS_SKIPPED_NO_SENDER = 'skipped_no_sender';
    public const STATUS_SKIPPED_DUPLICATE = 'skipped_duplicate';
    public const STATUS_FAILED            = 'failed';

    public const PROVIDER_ZOHO_MAIL = 'zoho_mail';

    protected $fillable = [
        'provider',
        'remote_message_id',
        'remote_uid',
        'remote_folder',
        'lead_id',
        'status',
        'sender_email',
        'subject',
        'has_attachments',
        'attachment_filenames',
        'error_message',
        'processed_at',
    ];

    protected $casts = [
        'has_attachments'      => 'boolean',
        'attachment_filenames' => 'array',
        'processed_at'         => 'datetime',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
