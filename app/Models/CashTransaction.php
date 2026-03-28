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
        'beds24_booking_id',
        'payment_method',
        'guest_name',
        'room_number',
        'occurred_at',
        // FX tracking — only populated on cross-currency guest payments
        'booking_currency',
        'booking_amount',
        'applied_exchange_rate',
        'reference_exchange_rate',
        'reference_rate_source',
        'reference_rate_date',
    ];

    protected $casts = [
        'type' => TransactionType::class,
        'category' => TransactionCategory::class,
        'amount'                  => 'decimal:2',
        'related_amount'          => 'decimal:2',
        'booking_amount'          => 'decimal:2',
        'applied_exchange_rate'   => 'decimal:4',
        'reference_exchange_rate' => 'decimal:4',
        'reference_rate_date'     => 'date',
        'occurred_at'             => 'datetime',
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
     * Whether this transaction has FX tracking data attached.
     */
    public function hasFxTracking(): bool
    {
        return $this->booking_amount !== null && $this->applied_exchange_rate !== null;
    }

    /**
     * collection_variance = UZS_received − (booking_USD × applied_rate)
     * Should be near zero; non-zero indicates rounding, negotiation, or typo.
     */
    public function collectionVariance(): ?float
    {
        if (!$this->hasFxTracking()) return null;
        return round((float)$this->amount - ((float)$this->booking_amount * (float)$this->applied_exchange_rate), 2);
    }

    /**
     * fx_variance = UZS_received − (booking_USD × reference_rate)
     * Management signal: how much above/below the official CBU rate was collected.
     * Positive = hotel collected above benchmark (gain).
     * Negative = hotel collected below benchmark (loss).
     */
    public function fxVariance(): ?float
    {
        if (!$this->hasFxTracking() || !$this->reference_exchange_rate) return null;
        return round((float)$this->amount - ((float)$this->booking_amount * (float)$this->reference_exchange_rate), 2);
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
