<?php

namespace App\Models;

use App\Enums\Currency;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EndSaldo extends Model
{
    use HasFactory;

    protected $fillable = [
        'cashier_shift_id',
        'currency',
        'expected_end_saldo',
        'counted_end_saldo',
        'discrepancy',
        'discrepancy_reason',
    ];

    protected $casts = [
        'currency' => Currency::class,
        'expected_end_saldo' => 'decimal:2',
        'counted_end_saldo' => 'decimal:2',
        'discrepancy' => 'decimal:2',
    ];

    public function cashierShift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class);
    }

    /**
     * Get formatted expected end saldo for display
     */
    public function getFormattedExpectedEndSaldoAttribute(): string
    {
        return $this->currency->formatAmount($this->expected_end_saldo);
    }

    /**
     * Get formatted counted end saldo for display
     */
    public function getFormattedCountedEndSaldoAttribute(): string
    {
        return $this->currency->formatAmount($this->counted_end_saldo ?? 0);
    }

    /**
     * Get formatted discrepancy for display
     */
    public function getFormattedDiscrepancyAttribute(): string
    {
        return $this->currency->formatAmount($this->discrepancy ?? 0);
    }

    /**
     * Check if there's a discrepancy
     */
    public function hasDiscrepancy(): bool
    {
        return $this->discrepancy !== null && abs($this->discrepancy) > 0.01;
    }
}