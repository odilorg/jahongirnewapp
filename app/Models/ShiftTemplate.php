<?php

namespace App\Models;

use App\Enums\Currency;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'cash_drawer_id',
        'currency',
        'amount',
        'last_shift_id',
        'has_discrepancy',
    ];

    protected $casts = [
        'currency' => Currency::class,
        'amount' => 'decimal:2',
        'has_discrepancy' => 'boolean',
    ];

    public function cashDrawer(): BelongsTo
    {
        return $this->belongsTo(CashDrawer::class);
    }

    public function lastShift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class, 'last_shift_id');
    }

    /**
     * Get formatted amount for display
     */
    public function getFormattedAmountAttribute(): string
    {
        return $this->currency->formatAmount($this->amount);
    }

    /**
     * Check if this template can be used (no discrepancies in last shift)
     */
    public function canBeUsed(): bool
    {
        return !$this->has_discrepancy;
    }
}

