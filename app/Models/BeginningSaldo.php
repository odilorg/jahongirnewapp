<?php

namespace App\Models;

use App\Enums\Currency;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BeginningSaldo extends Model
{
    use HasFactory;

    protected $fillable = [
        'cashier_shift_id',
        'currency',
        'amount',
    ];

    protected $casts = [
        'currency' => Currency::class,
        'amount' => 'decimal:2',
    ];

    /**
     * Get the cashier shift that owns this beginning saldo
     */
    public function cashierShift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class);
    }


    /**
     * Format amount with currency symbol
     */
    public function getFormattedAmountAttribute(): string
    {
        return $this->currency->formatAmount($this->amount);
    }
}