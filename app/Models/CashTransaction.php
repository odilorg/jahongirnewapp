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
        'income_category_id',
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

        // FX simplification — Phase 1 dual-write columns. See
        // docs/architecture/fx-simplification-plan.md.
        'reference_rate',
        'actual_rate',
        'deviation_pct',
        'was_overridden',
        // 'override_reason' is already in fillable above (legacy field reused).

        // Journal Entry Foundation — links sibling rows of one logical
        // transaction (split + group-bulk + future reversals/refunds).
        // 2026-05-08 incident: these fields were absent from $fillable,
        // so BotPaymentService::recordPayment() create() calls silently
        // dropped them — the cash leg of a split persisted with
        // journal_entry_id=NULL, then the card leg's duplicate-payment
        // guard saw the cash row as a "prior unrelated payment" because
        // the journal-id exemption couldn't match. Result: 100% of
        // splits failed with DuplicatePaymentException on the second
        // leg. Same silent-fillable pattern documented in
        // feedback_no_mass_assign_for_system_state. See docs/FIXES.md.
        'journal_entry_id',
        'payment_group_type',
        'base_currency_for_split',
        'journal_status',

        // Drawer-truth flag for beds24_external rows — Phase 1
        // (2026-05-11). The flag is set ONLY by the webhook handler
        // after evaluating five guards (see Beds24WebhookController
        // ::createExternalBookkeepingRow). Default false preserves
        // historical scopeDrawerTruth behaviour byte-for-byte. The
        // reason column records which guard failed when the flag
        // stayed false, so the Filament reconciliation page can
        // explain to the manager why the row needs manual review.
        // The flipped-by/at/note columns capture the audit trail
        // when a manager manually overrides the flag.
        'counts_as_drawer_truth',
        'drawer_truth_excluded_reason',
        'drawer_truth_flipped_by_user_id',
        'drawer_truth_flipped_at',
        'drawer_truth_flip_note',
    ];

    protected $casts = [
        'type' => TransactionType::class,
        'category' => TransactionCategory::class,
        'source_trigger' => CashTransactionSource::class,
        'override_tier' => OverrideTier::class,
        'amount' => 'decimal:2',
        'related_amount' => 'decimal:2',
        'usd_equivalent_paid' => 'decimal:2',
        'amount_presented_eur' => 'decimal:2',
        'amount_presented_rub' => 'decimal:2',
        'amount_presented_usd' => 'decimal:2',
        'amount_presented_selected' => 'decimal:2',
        'variance_pct' => 'decimal:2',
        'is_override' => 'boolean',
        'within_tolerance' => 'boolean',
        'is_group_payment' => 'boolean',
        'group_size_expected' => 'integer',
        'group_size_local' => 'integer',
        'occurred_at' => 'datetime',
        'presented_at' => 'datetime',
        'recorded_at' => 'datetime',
        'override_approved_at' => 'datetime',
        // Phase 1 simple-FX columns
        'reference_rate' => 'decimal:4',
        'actual_rate' => 'decimal:4',
        'deviation_pct' => 'decimal:4',
        'was_overridden' => 'boolean',

        // Drawer-truth flag for beds24_external rows (Phase 1).
        'counts_as_drawer_truth' => 'boolean',
        'drawer_truth_excluded_reason' => \App\Enums\DrawerTruthExcludedReason::class,
        'drawer_truth_flipped_at' => 'datetime',
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

    public function incomeCategory(): BelongsTo
    {
        return $this->belongsTo(IncomeCategory::class);
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
     *
     * Two paths qualify:
     *
     * Path A — cashier_bot / manual_admin rows with cash-or-null
     *   payment_method. This is the historical convention: the
     *   cashier-bot/admin UI writes these rows and the operator
     *   is held accountable for the physical drawer cash. NULL is
     *   treated as cash for legacy rows + bot-path expenses written
     *   before the `payment_method` column existed.
     *
     * Path B — beds24_external rows where `counts_as_drawer_truth=true`.
     *   These come from the Beds24 webhook (admin entered the cash
     *   payment in Beds24 admin instead of using the cashier-bot).
     *   The flag is set ONLY at write-time after all five guards pass:
     *
     *     1. payment_method is in cash allow-list
     *     2. occurred_at after the flag-day cutoff
     *     3. no matching cashier_bot row in ±2-min window
     *     4. an open cashier shift existed when the webhook arrived
     *     5. beds24_booking_id is non-null
     *
     *   See `Beds24WebhookController::createExternalBookkeepingRow` for
     *   the write-time guard chain. Because validation happened at
     *   write-time the scope here does NOT re-check payment_method —
     *   if the flag is true, the row is trusted as cash (the
     *   classifier may include "naqd" / "нал" / etc. which would not
     *   match Path A's literal `'cash'` filter).
     *
     * Excludes:
     *  - beds24_external rows with `counts_as_drawer_truth=false` —
     *    audit-only; manager can review and flip via Filament.
     *  - card/transfer/karta payment methods on Path A.
     *  - rows from any future source not in the two paths above.
     */
    public function scopeDrawerTruth($query)
    {
        return $query->where(function ($q) {
            // Path A: bot / manual-admin with cash-or-null method.
            $q->where(function ($q2) {
                $q2->whereIn('source_trigger', [
                    CashTransactionSource::CashierBot->value,
                    CashTransactionSource::ManualAdmin->value,
                ])
                    ->where(function ($q3) {
                        $q3->whereNull('payment_method')
                            ->orWhere('payment_method', '')
                            ->orWhere('payment_method', 'cash');
                    });
            })
            // Path B: beds24_external pre-validated at write-time.
                ->orWhere(function ($q2) {
                    $q2->where('source_trigger', CashTransactionSource::Beds24External->value)
                        ->where('counts_as_drawer_truth', true);
                });
        });
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
