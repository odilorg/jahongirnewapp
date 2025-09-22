<?php

namespace App\Models;

use App\Enums\Currency;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class ExchangeRate extends Model
{
    use HasFactory;

    protected $fillable = [
        'from_currency',
        'to_currency',
        'rate',
        'effective_date',
        'expires_at',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'from_currency' => Currency::class,
        'to_currency' => Currency::class,
        'rate' => 'decimal:6',
        'effective_date' => 'datetime',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    /**
     * Scope for active rates
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Scope for rates effective on a specific date
     */
    public function scopeEffectiveOn(Builder $query, $date): void
    {
        $query->where('effective_date', '<=', $date)
              ->where(function ($q) use ($date) {
                  $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', $date);
              });
    }

    /**
     * Scope for specific currency pair
     */
    public function scopeForCurrencyPair(Builder $query, Currency $from, Currency $to): void
    {
        $query->where('from_currency', $from)
              ->where('to_currency', $to);
    }

    /**
     * Get the current exchange rate for a currency pair
     */
    public static function getCurrentRate(Currency $from, Currency $to, $date = null): ?float
    {
        $date = $date ?? now();

        $rate = static::active()
            ->effectiveOn($date)
            ->forCurrencyPair($from, $to)
            ->orderBy('effective_date', 'desc')
            ->first();

        return $rate?->rate;
    }

    /**
     * Convert amount from one currency to another
     */
    public static function convert(float $amount, Currency $from, Currency $to, $date = null): float
    {
        if ($from === $to) {
            return $amount;
        }

        $rate = static::getCurrentRate($from, $to, $date);
        
        if (!$rate) {
            throw new \Exception("No exchange rate found for {$from->value} to {$to->value}");
        }

        return $amount * $rate;
    }

    /**
     * Get formatted rate string
     */
    public function getFormattedRateAttribute(): string
    {
        return "1 {$this->from_currency->value} = {$this->rate} {$this->to_currency->value}";
    }
}


