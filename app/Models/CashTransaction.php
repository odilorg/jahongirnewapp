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

    protected $fillable = [
        'cashier_shift_id',
        'type',
        'amount',
        'currency',
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
}
