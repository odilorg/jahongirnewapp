<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GuestPayment extends Model
{
    public const TYPE_DEPOSIT = 'deposit';
    public const TYPE_BALANCE = 'balance';
    public const TYPE_FULL    = 'full';
    public const TYPE_EXTRA   = 'extra';

    public const TYPES = [
        self::TYPE_FULL    => 'Full',
        self::TYPE_DEPOSIT => 'Deposit',
        self::TYPE_BALANCE => 'Balance',
        self::TYPE_EXTRA   => 'Extra',
    ];

    public const METHOD_OCTO          = 'octo';
    public const METHOD_CASH          = 'cash';
    public const METHOD_CARD_OFFICE   = 'card_office';
    public const METHOD_BANK_TRANSFER = 'bank_transfer';
    public const METHOD_PAYPAL        = 'paypal';
    public const METHOD_GYG           = 'gyg';
    public const METHOD_OTHER         = 'other';

    public const METHODS = [
        self::METHOD_CASH          => 'Cash',
        self::METHOD_OCTO          => 'Octo',
        self::METHOD_CARD_OFFICE   => 'Card (office)',
        self::METHOD_BANK_TRANSFER => 'Bank transfer',
        self::METHOD_PAYPAL        => 'PayPal',
        self::METHOD_GYG           => 'GYG (prepaid)',
        self::METHOD_OTHER         => 'Other',
    ];

    public const STATUS_RECORDED = 'recorded';
    public const STATUS_VOIDED   = 'voided';

    public const STATUSES = [
        self::STATUS_RECORDED => 'Recorded',
        self::STATUS_VOIDED   => 'Voided',
    ];

    protected $fillable = [
        'booking_inquiry_id',
        'amount',
        'currency',
        'payment_type',
        'payment_method',
        'payment_date',
        'reference',
        'notes',
        'receipt_path',
        'recorded_by_user_id',
        'status',
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'payment_date' => 'date',
    ];

    public function scopeRecorded($query)
    {
        return $query->where('status', self::STATUS_RECORDED);
    }

    public function bookingInquiry(): BelongsTo
    {
        return $this->belongsTo(BookingInquiry::class);
    }

    public function recordedByUser(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'recorded_by_user_id');
    }

    protected static function booted(): void
    {
        // Auto-recompute paid status whenever a payment is saved/deleted.
        // Centralized here so Octo webhook, manual entry, and GYG import
        // all flow through the same paid-marker logic.
        static::saved(fn (self $p) => $p->bookingInquiry?->recomputePaymentStatus());
        static::deleted(fn (self $p) => $p->bookingInquiry?->recomputePaymentStatus());
    }
}
