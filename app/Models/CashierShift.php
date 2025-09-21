<?php

namespace App\Models;

use App\Enums\ShiftStatus;
use App\Enums\Currency;
use App\Enums\TransactionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

class CashierShift extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'cash_drawer_id',
        'user_id',
        'status',
        'beginning_saldo',
        'expected_end_saldo',
        'counted_end_saldo',
        'discrepancy',
        'discrepancy_reason',
        'opened_at',
        'closed_at',
        'notes',
    ];

    protected $casts = [
        'status' => ShiftStatus::class,
        'beginning_saldo' => 'decimal:2',
        'expected_end_saldo' => 'decimal:2',
        'counted_end_saldo' => 'decimal:2',
        'discrepancy' => 'decimal:2',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    /**
     * Get the cash drawer that owns this shift
     */
    public function cashDrawer(): BelongsTo
    {
        return $this->belongsTo(CashDrawer::class);
    }

    /**
     * Get the user (cashier) that owns this shift
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get all transactions for this shift
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(CashTransaction::class);
    }

    /**
     * Get cash count for this shift (1:1 relationship)
     */
    public function cashCount(): HasOne
    {
        return $this->hasOne(CashCount::class);
    }

    /**
     * Scope for open shifts
     */
    public function scopeOpen($query)
    {
        return $query->where('status', ShiftStatus::OPEN);
    }

    /**
     * Scope for closed shifts
     */
    public function scopeClosed($query)
    {
        return $query->where('status', ShiftStatus::CLOSED);
    }

    /**
     * Scope for shifts by user
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope for shifts by drawer
     */
    public function scopeForDrawer($query, $drawerId)
    {
        return $query->where('cash_drawer_id', $drawerId);
    }

    /**
     * Check if shift is open
     */
    public function isOpen(): bool
    {
        return $this->status === ShiftStatus::OPEN;
    }

    /**
     * Check if shift is closed
     */
    public function isClosed(): bool
    {
        return $this->status === ShiftStatus::CLOSED;
    }

    /**
     * Get total cash in transactions
     */
    public function getTotalCashInAttribute(): float
    {
        return $this->transactions()
            ->where('type', 'in')
            ->sum('amount');
    }

    /**
     * Get total cash out transactions
     */
    public function getTotalCashOutAttribute(): float
    {
        return $this->transactions()
            ->where('type', 'out')
            ->sum('amount');
    }

    /**
     * Calculate expected end saldo
     */
    public function calculateExpectedEndSaldo(): float
    {
        return $this->beginning_saldo + $this->total_cash_in - $this->total_cash_out;
    }

    /**
     * Calculate discrepancy
     */
    public function calculateDiscrepancy(): ?float
    {
        if ($this->counted_end_saldo === null) {
            return null;
        }

        return $this->counted_end_saldo - $this->expected_end_saldo;
    }

    /**
     * Check if shift has discrepancy
     */
    public function hasDiscrepancy(): bool
    {
        return $this->discrepancy !== null && $this->discrepancy != 0;
    }

    /**
     * Get shift duration in hours
     */
    public function getDurationInHoursAttribute(): ?float
    {
        if (!$this->closed_at) {
            return null;
        }

        return $this->opened_at->diffInHours($this->closed_at, true);
    }

    /**
     * Get transactions grouped by currency
     */
    public function getTransactionsByCurrency(): Collection
    {
        return $this->transactions->groupBy('currency');
    }

    /**
     * Calculate total cash in for a specific currency
     */
    public function getTotalCashInForCurrency(Currency $currency): float
    {
        return $this->transactions()
            ->where('type', TransactionType::IN)
            ->where('currency', $currency)
            ->sum('amount');
    }

    /**
     * Calculate total cash out for a specific currency
     */
    public function getTotalCashOutForCurrency(Currency $currency): float
    {
        return $this->transactions()
            ->where('type', TransactionType::OUT)
            ->where('currency', $currency)
            ->sum('amount');
    }

    /**
     * Calculate net balance for a specific currency
     */
    public function getNetBalanceForCurrency(Currency $currency): float
    {
        return $this->getTotalCashInForCurrency($currency) - $this->getTotalCashOutForCurrency($currency);
    }

    /**
     * Get all currencies used in this shift
     */
    public function getUsedCurrencies(): Collection
    {
        return $this->transactions->pluck('currency')->unique();
    }
}
