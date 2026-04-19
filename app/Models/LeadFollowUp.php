<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LeadFollowUpStatus;
use App\Enums\LeadFollowUpType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadFollowUp extends Model
{
    use HasFactory;

    protected $table = 'lead_followups';

    protected $fillable = [
        'lead_id', 'lead_interest_id',
        'due_at', 'snoozed_until',
        'type', 'note',
        'status', 'completed_at', 'completed_by',
    ];

    protected $casts = [
        'due_at'        => 'datetime',
        'snoozed_until' => 'datetime',
        'completed_at'  => 'datetime',
        'type'          => LeadFollowUpType::class,
        'status'        => LeadFollowUpStatus::class,
    ];

    // "Due now" — status=open AND effective_due <= now (where effective_due respects an active snooze).
    public function scopeDue(Builder $q): Builder
    {
        return $q->where('status', LeadFollowUpStatus::Open->value)
            ->where(function ($q) {
                $q->whereNull('snoozed_until')
                    ->orWhere('snoozed_until', '<=', now());
            })
            ->where('due_at', '<=', now());
    }

    // "Overdue" = past due AND not currently snoozed into the future.
    public function scopeOverdue(Builder $q): Builder
    {
        return $q->where('status', LeadFollowUpStatus::Open->value)
            ->where('due_at', '<', now())
            ->where(function ($q) {
                $q->whereNull('snoozed_until')
                    ->orWhere('snoozed_until', '<=', now());
            });
    }

    public function scopeUpcoming(Builder $q, int $days = 7): Builder
    {
        return $q->where('status', LeadFollowUpStatus::Open->value)
            ->whereBetween('due_at', [now(), now()->addDays($days)]);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function interest(): BelongsTo
    {
        return $this->belongsTo(LeadInterest::class, 'lead_interest_id');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
