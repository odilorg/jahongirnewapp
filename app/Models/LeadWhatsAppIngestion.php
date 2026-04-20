<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit + dedupe for inbound WhatsApp → lead ingestion.
 *
 * Mirrors LeadEmailIngestion. One row per processed wacli message,
 * regardless of outcome. The (provider, remote_message_id) unique
 * constraint is the dedupe guarantee.
 */
class LeadWhatsAppIngestion extends Model
{
    use HasFactory;

    // Laravel's pluralizer would otherwise name this `lead_whats_app_ingestions`.
    protected $table = 'lead_whatsapp_ingestions';

    public const PROVIDER_WACLI = 'wacli';

    public const STATUS_PROCESSED         = 'processed';
    public const STATUS_AMBIGUOUS         = 'ambiguous';
    public const STATUS_SKIPPED_DUPLICATE = 'skipped_duplicate';
    public const STATUS_SKIPPED_SELF      = 'skipped_self';       // FromMe=true or sender in self_numbers
    public const STATUS_SKIPPED_GROUP     = 'skipped_group';      // *@g.us
    public const STATUS_SKIPPED_NO_PHONE  = 'skipped_no_phone';   // *@lid without phone hint
    public const STATUS_SKIPPED_BLOCKLIST = 'skipped_blocklist';
    public const STATUS_FAILED            = 'failed';

    protected $fillable = [
        'provider',
        'remote_message_id',
        'chat_jid',
        'sender_jid',
        'chat_name',
        'lead_id',
        'status',
        'sender_phone',
        'body_preview',
        'from_me',
        'has_media',
        'media_type',
        'remote_sent_at',
        'error_message',
        'processed_at',
    ];

    protected $casts = [
        'from_me'        => 'boolean',
        'has_media'      => 'boolean',
        'remote_sent_at' => 'datetime',
        'processed_at'   => 'datetime',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
