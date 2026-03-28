<?php

namespace App\Models;

use App\Services\DailyExchangeRate as DailyExchangeRateAlias;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingFxSync extends Model
{
    protected $fillable = [
        'beds24_booking_id',
        'fx_rate_date',
        'daily_exchange_rate_id',
        'usd_amount_used',
        'arrival_date_used',
        'uzs_final',
        'eur_final',
        'rub_final',
        'push_status',
        'fx_last_pushed_at',
        'last_push_error',
        'push_attempts',
        'last_source_trigger',
        'last_print_prepared_at',
        'infoitems_version',
    ];

    protected $casts = [
        'fx_rate_date'            => 'date',
        'arrival_date_used'       => 'date',
        'usd_amount_used'         => 'decimal:2',
        'fx_last_pushed_at'       => 'datetime',
        'last_print_prepared_at'  => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function rate(): BelongsTo
    {
        return $this->belongsTo(DailyExchangeRate::class, 'daily_exchange_rate_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Beds24Booking::class, 'beds24_booking_id', 'beds24_booking_id');
    }

    // -------------------------------------------------------------------------
    // Staleness check — single place for all staleness rules
    // -------------------------------------------------------------------------

    /**
     * Returns true if this sync row should be refreshed before use.
     *
     * Rules (short-circuit on first match):
     *  1. Push never completed or previously failed
     *  2. infoItems schema changed since this push (config fx.infoitems_version)
     *  3. Today's rate row doesn't exist yet — serve stale rather than blocking print/bot
     *  4. Synced rate date is not today
     *  5. Booking USD amount changed since last push
     *  6. Booking arrival date changed since last push
     *  7. Rate row margins/effective rates updated after last push
     */
    public function isStale(Beds24Booking $booking): bool
    {
        // 1. Never pushed or push failed
        if (in_array($this->push_status, ['pending', 'failed'])) {
            return true;
        }

        // 2. infoItems schema version bump
        if ($this->infoitems_version < (int) config('fx.infoitems_version', 1)) {
            return true;
        }

        // 3. Today's rate doesn't exist yet (e.g. 06:50am before morning job)
        //    Don't block — serve yesterday's values, repair job will fix at 07:00
        if (! DailyExchangeRate::where('rate_date', today())->exists()) {
            return false;
        }

        // 4. Rate date is not today
        if (! $this->fx_rate_date->isToday()) {
            return true;
        }

        // 5. USD amount changed
        if ((float) $this->usd_amount_used !== (float) $booking->effectiveUsdAmount()) {
            return true;
        }

        // 6. Arrival date changed
        if (! $this->arrival_date_used->eq($booking->arrival_date)) {
            return true;
        }

        // 7. Rate row was updated (margins changed) after last push
        $rate = DailyExchangeRate::find($this->daily_exchange_rate_id);
        if ($rate && $this->fx_last_pushed_at && $rate->updated_at->isAfter($this->fx_last_pushed_at)) {
            return true;
        }

        return false;
    }
}
