<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LeadInteractionChannel;
use App\Enums\LeadInteractionDirection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadInteraction extends Model
{
    use HasFactory;

    // Append-only — edits are an audit-integrity risk once inbound webhook ingestion is added.
    public const UPDATED_AT = null;

    protected $fillable = [
        'lead_id', 'user_id',
        'channel', 'direction',
        'subject', 'body',
        'is_important', 'raw_payload',
        'occurred_at',
    ];

    protected $casts = [
        'channel'      => LeadInteractionChannel::class,
        'direction'    => LeadInteractionDirection::class,
        'is_important' => 'boolean',
        'raw_payload'  => 'array',
        'occurred_at'  => 'datetime',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
