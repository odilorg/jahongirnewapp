<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierPayment extends Model
{
    public const TYPE_DRIVER        = 'driver';
    public const TYPE_GUIDE         = 'guide';
    public const TYPE_ACCOMMODATION = 'accommodation';

    public const TYPES = [
        self::TYPE_DRIVER        => 'Driver',
        self::TYPE_GUIDE         => 'Guide',
        self::TYPE_ACCOMMODATION => 'Accommodation',
    ];

    public const METHOD_CASH          = 'cash';
    public const METHOD_BANK_TRANSFER = 'bank_transfer';
    public const METHOD_CARD          = 'card';
    public const METHOD_OTHER         = 'other';

    public const METHODS = [
        self::METHOD_CASH          => 'Cash',
        self::METHOD_BANK_TRANSFER => 'Bank transfer',
        self::METHOD_CARD          => 'Card',
        self::METHOD_OTHER         => 'Other',
    ];

    public const STATUS_RECORDED = 'recorded';
    public const STATUS_VOIDED   = 'voided';

    public const STATUSES = [
        self::STATUS_RECORDED => 'Recorded',
        self::STATUS_VOIDED   => 'Voided',
    ];

    protected $fillable = [
        'supplier_type',
        'supplier_id',
        'booking_inquiry_id',
        'amount',
        'currency',
        'payment_date',
        'payment_method',
        'reference',
        'notes',
        'receipt_path',
        'paid_by_user_id',
        'status',
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'payment_date' => 'date',
    ];

    // ── Scopes ──────────────────────────────────────────

    public function scopeRecorded($query)
    {
        return $query->where('status', self::STATUS_RECORDED);
    }

    public function scopeForSupplier($query, string $type, int $id)
    {
        return $query->where('supplier_type', $type)->where('supplier_id', $id);
    }

    // ── Relations ───────────────────────────────────────

    public function bookingInquiry(): BelongsTo
    {
        return $this->belongsTo(BookingInquiry::class);
    }

    public function paidByUser(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'paid_by_user_id');
    }

    /**
     * Resolve the supplier name for display.
     */
    public function getSupplierNameAttribute(): string
    {
        return match ($this->supplier_type) {
            self::TYPE_DRIVER        => Driver::find($this->supplier_id)?->full_name ?? "Driver #{$this->supplier_id}",
            self::TYPE_GUIDE         => Guide::find($this->supplier_id)?->full_name ?? "Guide #{$this->supplier_id}",
            self::TYPE_ACCOMMODATION => Accommodation::find($this->supplier_id)?->name ?? "Acc #{$this->supplier_id}",
            default                  => "#{$this->supplier_id}",
        };
    }
}
