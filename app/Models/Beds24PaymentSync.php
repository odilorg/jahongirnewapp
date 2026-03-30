<?php

namespace App\Models;

use App\Enums\Beds24SyncStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Beds24PaymentSync extends Model
{
    use HasFactory;

    protected $fillable = [
        'cash_transaction_id',
        'beds24_booking_id',
        'local_reference',
        'beds24_payment_id',
        'amount_usd',
        'status',
        'push_attempts',
        'last_push_at',
        'last_error',
        'webhook_confirmed_at',
        'webhook_raw_payload',
    ];

    protected $casts = [
        'status'               => Beds24SyncStatus::class,
        'amount_usd'           => 'decimal:2',
        'push_attempts'        => 'integer',
        'last_push_at'         => 'datetime',
        'webhook_confirmed_at' => 'datetime',
        'webhook_raw_payload'  => 'array',
    ];

    public function cashTransaction(): BelongsTo
    {
        return $this->belongsTo(CashTransaction::class, 'cash_transaction_id');
    }

    public function isConfirmed(): bool
    {
        return $this->status === Beds24SyncStatus::Confirmed;
    }

    public function isPending(): bool
    {
        return $this->status === Beds24SyncStatus::Pending;
    }

    public function hasFailed(): bool
    {
        return $this->status === Beds24SyncStatus::Failed;
    }

    public function canRetry(): bool
    {
        return $this->status->canRetry();
    }

    /**
     * Scope for records that need to be pushed to Beds24.
     */
    public function scopeAwaitingPush($query): mixed
    {
        return $query->whereIn('status', [
            Beds24SyncStatus::Pending->value,
            Beds24SyncStatus::Failed->value,
        ]);
    }

    /**
     * Scope for records stuck in "pushing" state — likely a crashed job.
     */
    public function scopeStuckPushing($query, int $minutesThreshold = 5): mixed
    {
        return $query
            ->where('status', Beds24SyncStatus::Pushing->value)
            ->where('last_push_at', '<', now()->subMinutes($minutesThreshold));
    }
}
