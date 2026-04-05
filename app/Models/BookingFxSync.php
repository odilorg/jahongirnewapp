<?php

namespace App\Models;

use App\Enums\FxSyncPushStatus;
use App\Enums\FxSourceTrigger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;
use App\Models\Beds24Booking;

class BookingFxSync extends Model
{
    protected $fillable = [
        'beds24_booking_id',
        'fx_rate_date',
        'printed_rate_date',
        'exchange_rate_id',
        'usd_amount_used',
        'arrival_date_used',
        'uzs_final',
        'eur_final',
        'rub_final',
        'usd_final',
        'push_status',
        'fx_last_pushed_at',
        'last_push_error',
        'push_attempts',
        'last_source_trigger',
        'last_print_prepared_at',
        'infoitems_version',
    ];

    protected $casts = [
        'fx_rate_date'           => 'date',
        'printed_rate_date'      => 'date',
        'arrival_date_used'      => 'date',
        'usd_amount_used'        => 'decimal:2',
        'uzs_final'              => 'integer',
        'eur_final'              => 'decimal:2',
        'rub_final'              => 'decimal:2',
        'usd_final'              => 'decimal:2',
        'push_status'            => FxSyncPushStatus::class,
        'last_source_trigger'    => FxSourceTrigger::class,
        'fx_last_pushed_at'      => 'datetime',
        'last_print_prepared_at' => 'datetime',
        'push_attempts'          => 'integer',
        'infoitems_version'      => 'integer',
    ];

    public function exchangeRate(): BelongsTo
    {
        return $this->belongsTo(ExchangeRate::class, 'exchange_rate_id');
    }

    public function cashTransactions(): HasMany
    {
        return $this->hasMany(CashTransaction::class, 'booking_fx_sync_id');
    }

    /**
     * Whether this sync record needs to be recomputed.
     *
     * True when:
     *  - push never succeeded (failed or pending)
     *  - the rate date is older than today (a fresh daily rate is available)
     *  - no rate date is recorded (defensive guard)
     *
     * Called by FxSyncService::ensureFresh() to decide whether to re-push.
     */
    public function isStale(Beds24Booking $booking): bool
    {
        if ($this->push_status !== FxSyncPushStatus::Pushed) {
            return true;
        }

        if (! $this->fx_rate_date) {
            return true;
        }

        // Re-push when the nightly job has deposited a newer rate
        return $this->fx_rate_date->toDateString() < today()->toDateString();
    }

    /**
     * Whether the push to Beds24 infoItems has permanently failed.
     */
    public function pushFailed(): bool
    {
        return $this->push_status === FxSyncPushStatus::Failed;
    }

    /**
     * Whether infoItems were successfully pushed.
     */
    public function isPushed(): bool
    {
        return $this->push_status === FxSyncPushStatus::Pushed;
    }

    /**
     * The presentable amount for the given currency key (uppercase).
     */
    public function presentedAmountFor(string $currency): int|float
    {
        return match(strtoupper($currency)) {
            'UZS' => $this->uzs_final,
            'EUR' => (float) $this->eur_final,
            'RUB' => (float) $this->rub_final,
            'USD' => (float) $this->usd_final,
            default => throw new \InvalidArgumentException("Unknown currency: {$currency}"),
        };
    }
}
