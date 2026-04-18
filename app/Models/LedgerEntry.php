<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CounterpartyType;
use App\Enums\LedgerDataQuality;
use App\Enums\LedgerEntryDirection;
use App\Enums\LedgerEntryType;
use App\Enums\OverrideTier;
use App\Enums\PaymentMethod;
use App\Enums\SourceTrigger;
use App\Enums\TrustLevel;
use App\Exceptions\Ledger\LedgerImmutableException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * L-003 — canonical append-only ledger row.
 *
 * Every money event in the system produces one row in this table.
 * Rows are immutable: adjustments, refunds and reversals are NEW rows
 * that reference the original via reverses_entry_id or parent_entry_id.
 *
 * Not yet wired into production flows. The only permitted writer will
 * be App\Actions\Ledger\RecordLedgerEntry (introduced in L-004). The
 * runtime write-firewall (L-018) and CI guard (L-017) enforce this.
 */
class LedgerEntry extends Model
{
    use HasFactory;

    protected $table = 'ledger_entries';

    /** Ledger is append-only — there is no updated_at column. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'ulid',
        'idempotency_key',
        'occurred_at',
        'recorded_at',
        'entry_type',
        'source',
        'trust_level',
        'direction',
        'amount',
        'currency',
        'fx_rate',
        'fx_rate_date',
        'daily_exchange_rate_id',
        'exchange_rate_id',
        'presentation_snapshot',
        'usd_equivalent',
        'counterparty_type',
        'counterparty_id',
        'booking_inquiry_id',
        'beds24_booking_id',
        'cashier_shift_id',
        'cash_drawer_id',
        'payment_method',
        'override_tier',
        'override_approval_id',
        'variance_pct',
        'parent_entry_id',
        'reverses_entry_id',
        'external_reference',
        'external_item_ref',
        'created_by_user_id',
        'created_by_bot_slug',
        'notes',
        'tags',
        'data_quality',
        'created_at',
    ];

    protected $casts = [
        'entry_type'            => LedgerEntryType::class,
        'source'                => SourceTrigger::class,
        'trust_level'           => TrustLevel::class,
        'direction'             => LedgerEntryDirection::class,
        'amount'                => 'decimal:2',
        'fx_rate'               => 'decimal:4',
        'fx_rate_date'          => 'date',
        'usd_equivalent'        => 'decimal:2',
        'counterparty_type'     => CounterpartyType::class,
        'payment_method'        => PaymentMethod::class,
        'variance_pct'          => 'decimal:2',
        'override_tier'         => OverrideTier::class,
        'presentation_snapshot' => 'array',
        'tags'                  => 'array',
        'data_quality'          => LedgerDataQuality::class,
        'occurred_at'           => 'datetime',
        'recorded_at'           => 'datetime',
    ];

    /**
     * Append-only invariant.
     *
     * Refuse in-place updates and deletes at the model layer. The runtime
     * write-firewall (L-018) extends this by also blocking direct
     * LedgerEntry::create() outside the canonical RecordLedgerEntry action.
     */
    protected static function booted(): void
    {
        static::updating(function (LedgerEntry $entry): void {
            throw new LedgerImmutableException(
                "LedgerEntry #{$entry->id} cannot be updated — ledger is append-only. "
                . 'Record a reversal via RecordLedgerEntry with reverses_entry_id instead.'
            );
        });

        static::deleting(function (LedgerEntry $entry): void {
            throw new LedgerImmutableException(
                "LedgerEntry #{$entry->id} cannot be deleted — ledger is append-only."
            );
        });
    }

    // ---------------------------------------------------------------------
    // Relationships — read-only; ledger state never mutates through Eloquent
    // ---------------------------------------------------------------------

    public function parentEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_entry_id');
    }

    public function childLegs(): HasMany
    {
        return $this->hasMany(self::class, 'parent_entry_id');
    }

    public function reversesEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }

    /**
     * Derived on read — the entry that reversed THIS row, if any.
     * Not a stored column so that the ledger remains strictly append-only.
     */
    public function reversals(): HasMany
    {
        return $this->hasMany(self::class, 'reverses_entry_id');
    }

    public function bookingInquiry(): BelongsTo
    {
        return $this->belongsTo(BookingInquiry::class, 'booking_inquiry_id');
    }

    public function cashierShift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class, 'cashier_shift_id');
    }

    public function cashDrawer(): BelongsTo
    {
        return $this->belongsTo(CashDrawer::class, 'cash_drawer_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function dailyExchangeRate(): BelongsTo
    {
        return $this->belongsTo(DailyExchangeRate::class, 'daily_exchange_rate_id');
    }

    public function exchangeRate(): BelongsTo
    {
        return $this->belongsTo(ExchangeRate::class, 'exchange_rate_id');
    }

    public function overrideApproval(): BelongsTo
    {
        return $this->belongsTo(FxManagerApproval::class, 'override_approval_id');
    }

    // ---------------------------------------------------------------------
    // Query helpers
    // ---------------------------------------------------------------------

    /**
     * Signed amount (positive for in, negative for out) — usable in SUM() calls.
     */
    public function signedAmount(): float
    {
        return (float) $this->amount * $this->direction->sign();
    }
}
