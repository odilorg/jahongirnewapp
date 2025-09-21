<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashCount extends Model
{
    use HasFactory;

    protected $fillable = [
        'cashier_shift_id',
        'denominations',
        'total',
        'notes',
    ];

    protected $casts = [
        'denominations' => 'array',
        'total' => 'decimal:2',
    ];

    /**
     * Get the shift that owns this cash count
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class, 'cashier_shift_id');
    }

    /**
     * Calculate total from denominations
     */
    public function calculateTotalFromDenominations(): float
    {
        $total = 0;
        
        foreach ($this->denominations as $denomination) {
            $total += $denomination['denomination'] * $denomination['qty'];
        }

        return $total;
    }

    /**
     * Validate that total matches denominations
     */
    public function validateTotal(): bool
    {
        return abs($this->total - $this->calculateTotalFromDenominations()) < 0.01;
    }

    /**
     * Get formatted denominations for display
     */
    public function getFormattedDenominationsAttribute(): array
    {
        $formatted = [];
        
        foreach ($this->denominations as $denomination) {
            $formatted[] = [
                'denomination' => number_format($denomination['denomination'], 0),
                'qty' => $denomination['qty'],
                'subtotal' => number_format($denomination['denomination'] * $denomination['qty'], 2),
            ];
        }

        return $formatted;
    }
}

