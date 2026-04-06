<?php

namespace App\Models;

use App\Enums\CashTransactionSource;
use App\Enums\Currency;
use App\Enums\OverrideTier;
use App\Enums\TransactionCategory;
use App\Enums\TransactionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class CashTransaction extends Model
{
    use HasFactory, SoftDeletes;

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($transaction) {
            if (! $transaction->occurred_at) {
                $transaction->occurred_at = now();
            }

            if (! $transaction->created_by && auth()->check()) {
                $transaction->created_by = auth()->id();
            }
        });
    }

    protected $fillable = [
        // Core (pre-existing)
        'cashier_shift_id',
        'type',
        'amount',
        'currency',
        'related_currency',
        'related_amount',
        'category',
        'reference',
        'notes',
        'created_by',
        'beds24_booking_id',
        'payment_method',
        'guest_name',
        'room_number',
        'occurred_at',

        // FX-system additions
        'source_trigger',
        'booking_fx_sync_id',
        'exchange_rate_id',
        'daily_exchange_rate_id',

        // Presentation snapshot (frozen at time of bot confirmation)
        'amount_presented_uzs',
        'amount_presented_eur',
        'amount_presented_rub',
        'amount_presented_usd',
        'presented_currency',
        'amount_presented_selected',

        // USD equivalent for drawer reconciliation
        'usd_equivalent_paid',

        // Override / tolerance
        'is_override',
        'within_tolerance',
        'variance_pct',
        'override_tier',
        'override_reason',
        'override_approved_by',
        'override_approved_at',
        'override_approval_id',

        // Session & sync tracking
        'presented_at',
        'recorded_at',
        'bot_session_id',
        'beds24_payment_sync_id',
        'beds24_payment_ref',

        // Group booking audit (null for standalone bookings)
        'group_master_booking_id',
        'is_group_payment',
        'group_size_expected',
        'group_size_local',
    ];

    protected $casts = [
        'type'                    => TransactionType::class,
        'category'                => TransactionCategory::class,
        'source_trigger'          => CashTransactionSource::class,
        'override_tier'           => OverrideTier::class,
        'amount'                  => 'decimal:2',
        'related_amount'          => 'decimal:2',
        'usd_equivalent_paid'     => 'decimal:2',
        'amount_presented_eur'    => 'decimal:2',
        'amount_presented_rub'    => 'decimal:2',
        'amount_presented_usd'    => 'decimal:2',
        'amount_presented_selected' => 'decimal:2',
        'variance_pct'            => 'decimal:2',
        'is_override'             => 'boolean',
        'within_tolerance'        => 'boolean',
        'is_group_payment'        => 'boolean',
        'group_size_expected'     => 'integer',
        'group_size_local'        => 'integer',
        'occurred_at'             => 'datetime',
        'presented_at'            => 'datetime',
        'recorded_at'             => 'datetime',
        'override_approved_at'    => 'datetime',
    ];

    // -----------------------------------------------------------------------
    // Relationships
    // -----------------------------------------------------------------------

    public function shift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class, 'cashier_shift_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function bookingFxSync(): BelongsTo
    {
        return $this->belongsTo(BookingFxSync::class, 'booking_fx_sync_id');
    }

    public function exchangeRate(): BelongsTo
    {
        return $this->belongsTo(ExchangeRate::class, 'exchange_rate_id');
    }

    /**
     * The Beds24 push-sync record for this payment.
     * Uses the FK column beds24_payment_sync_id (set after sync row is created).
     */
    public function paymentSync(): BelongsTo
    {
        return $this->belongsTo(Beds24PaymentSync::class, 'beds24_payment_sync_id');
    }

    /**
     * The manager approval that authorised this override payment.
     */
    public function approvalRecord(): BelongsTo
    {
        return $this->belongsTo(FxManagerApproval::class, 'override_approval_id');
    }

    // -----------------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------------

    public function scopeCashIn($query)
    {
        return $query->where('type', TransactionType::IN);
    }

    public function scopeCashOut($query)
    {
        return $query->where('type', TransactionType::OUT);
    }

    public function scopeByCategory($query, TransactionCategory $category)
    {
        return $query->where('category', $category);
    }

    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('occurred_at', [$startDate, $endDate]);
    }

    /**
     * Only rows that count toward the physical drawer balance.
     * Excludes beds24_external rows, which are Beds24-originated duplicates.
     */
    public function scopeDrawerTruth($query)
    {
        return $query->whereIn('source_trigger', [
            CashTransactionSource::CashierBot->value,
            CashTransactionSource::ManualAdmin->value,
        ]);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    public function getEffectiveAmountAttribute(): float
    {
        return $this->amount * $this->type->multiplier();
    }

    public function isCashIn(): bool
    {
        return $this->type === TransactionType::IN;
    }

    public function isCashOut(): bool
    {
        return $this->type === TransactionType::OUT;
    }

    /**
     * Whether this row is authoritative for the physical cash drawer.
     */
    public function isDrawerTruth(): bool
    {
        return $this->source_trigger?->isDrawerTruth() ?? false;
    }

    public function getCurrencyAttribute($value): Currency
    {
        return Currency::from($value);
    }

    public function setCurrencyAttribute($value): void
    {
        $this->attributes['currency'] = $value instanceof Currency ? $value->value : $value;
    }

    public function getRelatedCurrencyAttribute($value): ?Currency
    {
        return $value ? Currency::from($value) : null;
    }

    public function setRelatedCurrencyAttribute($value): void
    {
        if ($value instanceof Currency) {
            $this->attributes['related_currency'] = $value->value;
        } else {
            $this->attributes['related_currency'] = $value;
        }
    }

    public function isExchange(): bool
    {
        return $this->type === TransactionType::IN_OUT && ! empty($this->related_currency);
    }

    public function getExchangeDetails(): ?string
    {
        if (! $this->isExchange()) {
            return null;
        }

        return "{$this->currency->formatAmount($this->amount)} → {$this->related_currency->formatAmount($this->related_amount)}";
    }
}
