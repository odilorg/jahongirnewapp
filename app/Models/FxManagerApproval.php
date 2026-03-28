<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FxManagerApproval extends Model
{
    protected $fillable = [
        'beds24_booking_id',
        'bot_session_id',
        'cashier_id',
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
        'amount_presented'  => 'decimal:2',
        'amount_proposed'   => 'decimal:2',
        'variance_pct'      => 'decimal:2',
        'resolved_at'       => 'datetime',
        'expires_at'        => 'datetime',
    ];

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(CashTransaction::class, 'used_in_cash_transaction_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
