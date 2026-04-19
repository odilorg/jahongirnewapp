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

    // Effective due = snooze wins if set and later than due_at, otherwise due_at.
    // Centralized so every section of the follow-up queue asks the same question.
    public const EFFECTIVE_DUE_SQL =
        '(CASE WHEN snoozed_until IS NOT NULL AND snoozed_until > due_at THEN snoozed_until ELSE due_at END)';

    public function scopeDue(Builder $q): Builder
    {
        return $q->where('lead_followups.status', LeadFollowUpStatus::Open->value)
            ->whereRaw(self::EFFECTIVE_DUE_SQL.' <= ?', [now()]);
    }

    public function scopeOverdue(Builder $q): Builder
    {
        return $q->where('lead_followups.status', LeadFollowUpStatus::Open->value)
            ->whereRaw(self::EFFECTIVE_DUE_SQL.' < ?', [now()]);
    }

    public function scopeDueToday(Builder $q): Builder
    {
        return $q->where('lead_followups.status', LeadFollowUpStatus::Open->value)
            ->whereRaw(self::EFFECTIVE_DUE_SQL.' >= ?', [now()])
            ->whereRaw(self::EFFECTIVE_DUE_SQL.' <= ?', [now()->endOfDay()]);
    }

    public function scopeUpcoming(Builder $q, int $days = 7): Builder
    {
        return $q->where('lead_followups.status', LeadFollowUpStatus::Open->value)
            ->whereRaw(self::EFFECTIVE_DUE_SQL.' > ?', [now()->endOfDay()])
            ->whereRaw(self::EFFECTIVE_DUE_SQL.' <= ?', [now()->addDays($days)->endOfDay()]);
    }

    // Sort by priority (urgent first) then effective_due ASC. Requires the
    // leads join to be present on the query.
    public function scopeOrderByPriorityThenDue(Builder $q): Builder
    {
        return $q->orderByRaw("CASE leads.priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END ASC")
            ->orderByRaw(self::EFFECTIVE_DUE_SQL.' ASC');
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
