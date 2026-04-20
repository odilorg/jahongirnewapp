<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LeadContactChannel;
use App\Enums\LeadPriority;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name', 'phone', 'email',
        'whatsapp_number', 'telegram_username', 'telegram_chat_id',
        'preferred_channel', 'source',
        'language', 'country',
        'status', 'priority', 'waiting_reason',
        'assigned_to', 'notes',
    ];

    protected $casts = [
        'status'              => LeadStatus::class,
        'priority'            => LeadPriority::class,
        'source'              => LeadSource::class,
        'last_interaction_at' => 'datetime',
        'next_followup_at'    => 'datetime',
    ];

    // Nullable enum column — native cast rejects NULLs on older Laravel minors, so route through an accessor.
    protected function preferredChannel(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value ? LeadContactChannel::from($value) : null,
            set: fn ($value) => $value instanceof LeadContactChannel ? $value->value : $value,
        );
    }

    public function interests(): HasMany
    {
        return $this->hasMany(LeadInterest::class);
    }

    public function interactions(): HasMany
    {
        return $this->hasMany(LeadInteraction::class);
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(LeadFollowUp::class);
    }

    // Powers the "Last contact" column on the follow-up queue.
    public function latestInteraction(): HasOne
    {
        return $this->hasOne(LeadInteraction::class)->latestOfMany('occurred_at');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function scopeNeedsAttention(Builder $q): Builder
    {
        return $q->whereNotIn('status', [
            LeadStatus::Converted->value,
            LeadStatus::Lost->value,
        ]);
    }

    // Portable "nulls last" — (col IS NULL) returns 0/false for non-nulls and
    // 1/true for nulls, so ordering asc puts real dates before nulls on both
    // MySQL and Postgres.
    public function scopeQueue(Builder $q): Builder
    {
        return $q->orderByRaw('(next_followup_at IS NULL) ASC, next_followup_at ASC')
            ->orderByDesc('last_interaction_at');
    }

    public function hasOverdueFollowups(): bool
    {
        return $this->followUps()->overdue()->exists();
    }

    // Called by LeadFollowUpObserver after any followup write.
    public function refreshNextFollowupAt(): void
    {
        $next = $this->followUps()
            ->where('status', 'open')
            ->selectRaw('MIN(CASE WHEN snoozed_until IS NOT NULL AND snoozed_until > due_at THEN snoozed_until ELSE due_at END) as next_due')
            ->value('next_due');

        // Skip the write if unchanged — observer fires on every save; avoid noise.
        if ((string) $this->next_followup_at !== (string) $next) {
            $this->forceFill(['next_followup_at' => $next])->saveQuietly();
        }
    }
}
