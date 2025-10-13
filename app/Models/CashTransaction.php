<?php

namespace App\Models;

use App\Enums\TransactionCategory;
use App\Enums\TransactionType;
use App\Enums\Currency;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CashTransaction extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Boot method - Auto-set timestamps and user
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($transaction) {
            // Auto-set occurred_at if not provided
            if (!$transaction->occurred_at) {
                $transaction->occurred_at = now();
            }

            // Auto-set created_by if not provided
            if (!$transaction->created_by && auth()->check()) {
                $transaction->created_by = auth()->id();
            }
        });
    }

    protected $fillable = [
        'cashier_shift_id',
        'type',
        'amount',
        'currency',
        'related_currency',
        'related_amount',
        'category',
        'reference',
        'notes',
        'created_by',
        'occurred_at',
    ];

    protected $casts = [
        'type' => TransactionType::class,
        'category' => TransactionCategory::class,
        'amount' => 'decimal:2',
        'related_amount' => 'decimal:2',
        'occurred_at' => 'datetime',
    ];

    /**
     * Get the shift that owns this transaction
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class, 'cashier_shift_id');
    }

    /**
     * Get the user who created this transaction
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Scope for cash in transactions
     */
    public function scopeCashIn($query)
    {
        return $query->where('type', TransactionType::IN);
    }

    /**
     * Scope for cash out transactions
     */
    public function scopeCashOut($query)
    {
        return $query->where('type', TransactionType::OUT);
    }

    /**
     * Scope for transactions by category
     */
    public function scopeByCategory($query, TransactionCategory $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Scope for transactions by date range
     */
    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('occurred_at', [$startDate, $endDate]);
    }

    /**
     * Get the effective amount (positive for IN, negative for OUT)
     */
    public function getEffectiveAmountAttribute(): float
    {
        return $this->amount * $this->type->multiplier();
    }

    /**
     * Check if transaction is cash in
     */
    public function isCashIn(): bool
    {
        return $this->type === TransactionType::IN;
    }

    /**
     * Check if transaction is cash out
     */
    public function isCashOut(): bool
    {
        return $this->type === TransactionType::OUT;
    }

    /**
     * Get currency as enum
     */
    public function getCurrencyAttribute($value): Currency
    {
        return Currency::from($value);
    }

    /**
     * Set currency from enum
     */
    public function setCurrencyAttribute($value): void
    {
        if ($value instanceof Currency) {
            $this->attributes['currency'] = $value->value;
        } else {
            $this->attributes['currency'] = $value;
        }
    }

    /**
     * Get related currency as enum
     */
    public function getRelatedCurrencyAttribute($value): ?Currency
    {
        return $value ? Currency::from($value) : null;
    }

    /**
     * Set related currency from enum
     */
    public function setRelatedCurrencyAttribute($value): void
    {
        if ($value instanceof Currency) {
            $this->attributes['related_currency'] = $value->value;
        } elseif ($value) {
            $this->attributes['related_currency'] = $value;
        } else {
            $this->attributes['related_currency'] = null;
        }
    }

    /**
     * Check if this is an exchange transaction
     */
    public function isExchange(): bool
    {
        return $this->type === TransactionType::IN_OUT && !empty($this->related_currency);
    }

    /**
     * Get formatted exchange details
     */
    public function getExchangeDetails(): ?string
    {
        if (!$this->isExchange()) {
            return null;
        }

        $inCurrency = $this->currency;
        $outCurrency = $this->related_currency;

        return "{$inCurrency->formatAmount($this->amount)} → {$outCurrency->formatAmount($this->related_amount)}";
    }
}
