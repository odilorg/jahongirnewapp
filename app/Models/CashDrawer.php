<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Enums\Currency;

class CashDrawer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'location',
        'location_id',
        'is_active',
        'balances',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'balances' => 'array',
    ];

    /**
     * Get the location that owns this drawer
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

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

    /**
     * Get balance for a specific currency
     */
    public function getBalanceForCurrency(string $currency): float
    {
        $balances = $this->balances ?? [];
        return $balances[$currency] ?? 0;
    }

    /**
     * Set balance for a specific currency
     */
    public function setBalanceForCurrency(string $currency, float $amount): void
    {
        $balances = $this->balances ?? [];
        $balances[$currency] = $amount;
        $this->balances = $balances;
        $this->save();
    }

    /**
     * Update balance for a specific currency (add/subtract)
     */
    public function updateBalanceForCurrency(string $currency, float $amount): void
    {
        $currentBalance = $this->getBalanceForCurrency($currency);
        $this->setBalanceForCurrency($currency, $currentBalance + $amount);
    }

    /**
     * Get all balances with formatting
     */
    public function getFormattedBalances(): array
    {
        $balances = $this->balances ?? [];
        $formatted = [];

        foreach ($balances as $currency => $amount) {
            try {
                $currencyEnum = Currency::from($currency);
                $formatted[] = $currencyEnum->formatAmount($amount);
            } catch (\Exception $e) {
                $formatted[] = "$amount $currency";
            }
        }

        return $formatted;
    }

    /**
     * Initialize balances from closed shift
     */
    public function initializeBalancesFromShift(CashierShift $shift): void
    {
        $balances = [];

        // Get end saldos from closed shift
        foreach ($shift->endSaldos as $endSaldo) {
            $balances[$endSaldo->currency->value] = (float) $endSaldo->counted_end_saldo;
        }

        $this->balances = $balances;
        $this->save();
    }
}
