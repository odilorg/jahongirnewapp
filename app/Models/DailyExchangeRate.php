<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyExchangeRate extends Model
{
    protected $fillable = [
        'rate_date',
        'usd_uzs_rate',
        'eur_uzs_cbu_rate',
        'eur_margin',
        'eur_effective_rate',
        'rub_uzs_cbu_rate',
        'rub_margin',
        'rub_effective_rate',
        'uzs_rounding_increment',
        'eur_rounding_increment',
        'rub_rounding_increment',
        'set_by_user_id',
        'source',
        'fetched_at',
    ];

    protected $casts = [
        'rate_date'              => 'date',
        'usd_uzs_rate'           => 'decimal:4',
        'eur_uzs_cbu_rate'       => 'decimal:4',
        'eur_effective_rate'     => 'decimal:4',
        'rub_uzs_cbu_rate'       => 'decimal:4',
        'rub_effective_rate'     => 'decimal:4',
        'fetched_at'             => 'datetime',
    ];

    public function setBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by_user_id');
    }

    /** Today's rate row, or null if the morning job hasn't run yet. */
    public static function today(): ?self
    {
        return static::where('rate_date', today()->toDateString())->first();
    }

    /** The most-recent rate row available (today or yesterday as fallback). */
    public static function latest(): ?self
    {
        return static::orderByDesc('rate_date')->first();
    }
}
