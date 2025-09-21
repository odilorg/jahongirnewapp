<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CashDrawer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'location',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get all shifts for this drawer
     */
    public function shifts(): HasMany
    {
        return $this->hasMany(CashierShift::class);
    }

    /**
     * Get open shifts for this drawer
     */
    public function openShifts(): HasMany
    {
        return $this->hasMany(CashierShift::class)->where('status', 'open');
    }

    /**
     * Get closed shifts for this drawer
     */
    public function closedShifts(): HasMany
    {
        return $this->hasMany(CashierShift::class)->where('status', 'closed');
    }

    /**
     * Scope for active drawers
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Get current expected balance for this drawer
     */
    public function getCurrentBalanceAttribute(): float
    {
        $openShift = $this->openShifts()->first();
        
        if (!$openShift) {
            return 0;
        }

        // For open shifts, calculate running balance from transactions
        if ($openShift->status->value === 'open') {
            return $openShift->beginning_saldo + $openShift->total_cash_in - $openShift->total_cash_out;
        }

        // For closed shifts, use the expected end saldo
        return $openShift->expected_end_saldo ?? $openShift->beginning_saldo;
    }

    /**
     * Check if drawer has any open shifts
     */
    public function hasOpenShifts(): bool
    {
        return $this->openShifts()->exists();
    }

    /**
     * Get the current open shift for this drawer
     */
    public function getCurrentOpenShift(): ?CashierShift
    {
        return $this->openShifts()->first();
    }
}
