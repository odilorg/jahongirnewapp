<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only record of every Octo payment link ever generated for an
 * inquiry. Introduced in Phase 1 of the regenerate-payment-link rollout.
 *
 * Each row is a frozen snapshot — amounts and FX reflect the state at
 * attempt creation, not the current inquiry state. This matters because
 * an operator may regenerate with a different amount; a webhook arriving
 * on the OLD link must record the OLD amount, not the new one.
 *
 * Lifecycle (one-way transitions):
 *   active      — currently-active link for the inquiry (≤1 per inquiry)
 *   superseded  — replaced by a newer attempt via operator regenerate
 *                 (still payable on Octo's side until it expires)
 *   paid        — callback success received for this attempt
 *   failed      — callback failure received (operator can regenerate)
 *
 * Phase 1 is shadow-mode: rows are written but nothing reads them.
 * Phase 2 flips OctoCallbackController to resolve inquiries via this
 * table instead of via booking_inquiries.octo_transaction_id.
 */
class OctoPaymentAttempt extends Model
{
    public const STATUS_ACTIVE     = 'active';
    public const STATUS_SUPERSEDED = 'superseded';
    public const STATUS_PAID       = 'paid';
    public const STATUS_FAILED     = 'failed';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_SUPERSEDED,
        self::STATUS_PAID,
        self::STATUS_FAILED,
    ];

    protected $fillable = [
        'inquiry_id',
        'transaction_id',
        'amount_online_usd',
        'price_quoted_at_attempt',
        'exchange_rate_used',
        'uzs_amount',
        'status',
        'superseded_at',
    ];

    protected $casts = [
        'amount_online_usd'       => 'decimal:2',
        'price_quoted_at_attempt' => 'decimal:2',
        'exchange_rate_used'      => 'decimal:4',
        'uzs_amount'              => 'integer',
        'superseded_at'           => 'datetime',
    ];

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(BookingInquiry::class, 'inquiry_id');
    }
}
