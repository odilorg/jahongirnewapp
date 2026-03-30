<?php

namespace App\Models;

use App\Enums\Currency;
use App\Enums\ManagerApprovalStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FxManagerApproval extends Model
{
    protected $fillable = [
        'beds24_booking_id',
        'bot_session_id',
        'cashier_id',
        'manager_notified_id',
        'currency',
        'amount_presented',
        'amount_proposed',
        'variance_pct',
        'status',
        'resolved_by',
        'resolved_at',
        'rejection_reason',
        'expires_at',
        'used_in_cash_transaction_id',
    ];

    protected $casts = [
        'currency'                  => Currency::class,
        'amount_presented'          => 'decimal:2',
        'amount_proposed'           => 'decimal:2',
        'variance_pct'              => 'decimal:2',
        'status'                    => ManagerApprovalStatus::class,
        'resolved_at'               => 'datetime',
        'expires_at'                => 'datetime',
    ];

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function managerNotified(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_notified_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function consumedTransaction(): BelongsTo
    {
        return $this->belongsTo(CashTransaction::class, 'used_in_cash_transaction_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isPending(): bool
    {
        return $this->status === ManagerApprovalStatus::Pending;
    }

    public function canBeConsumed(): bool
    {
        return $this->status->canBeConsumed() && ! $this->isExpired();
    }

    /**
     * Scope for approvals that are pending and not yet expired.
     */
    public function scopeActiveFor($query, string $beds24BookingId): mixed
    {
        return $query
            ->where('beds24_booking_id', $beds24BookingId)
            ->where('status', ManagerApprovalStatus::Pending)
            ->where('expires_at', '>', now());
    }
}
