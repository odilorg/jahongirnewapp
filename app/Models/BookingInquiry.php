<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Website booking inquiry — a lead, not a confirmed booking.
 *
 * See database/migrations/2026_04_15_000001_create_booking_inquiries_table.php
 * for the rationale behind the deliberate separation from the legacy
 * `bookings`/`tours` flow.
 */
class BookingInquiry extends Model
{
    use HasFactory;

    public const STATUS_NEW               = 'new';
    public const STATUS_CONTACTED         = 'contacted';
    public const STATUS_AWAITING_CUSTOMER = 'awaiting_customer';
    public const STATUS_AWAITING_PAYMENT  = 'awaiting_payment';
    public const STATUS_CONFIRMED         = 'confirmed';
    public const STATUS_CANCELLED         = 'cancelled';
    public const STATUS_SPAM              = 'spam';

    public const STATUSES = [
        self::STATUS_NEW,
        self::STATUS_CONTACTED,
        self::STATUS_AWAITING_CUSTOMER,
        self::STATUS_AWAITING_PAYMENT,
        self::STATUS_CONFIRMED,
        self::STATUS_CANCELLED,
        self::STATUS_SPAM,
    ];

    public const PAYMENT_ONLINE      = 'online';
    public const PAYMENT_CASH        = 'cash';
    public const PAYMENT_CARD_OFFICE = 'card_office';

    // Operational lifecycle — parallel to commercial `status`.
    // A confirmed sale moves through these prep states independently.
    public const PREP_NOT_PREPARED = 'not_prepared';
    public const PREP_PREPARED     = 'prepared';
    public const PREP_DISPATCHED   = 'dispatched';
    public const PREP_COMPLETED    = 'completed';

    public const PREP_STATUSES = [
        self::PREP_NOT_PREPARED,
        self::PREP_PREPARED,
        self::PREP_DISPATCHED,
        self::PREP_COMPLETED,
    ];

    protected $fillable = [
        'reference',
        'source',
        'tour_slug',
        'tour_name_snapshot',
        'page_url',
        'customer_name',
        'customer_email',
        'customer_phone',
        'customer_country',
        'preferred_contact',
        'people_adults',
        'people_children',
        'travel_date',
        'flexible_dates',
        'message',
        'price_quoted',
        'currency',
        'payment_method',
        'payment_link',
        'payment_link_sent_at',
        'paid_at',
        'octo_transaction_id',
        'booking_id',
        'driver_id',
        'guide_id',
        'pickup_time',
        'pickup_point',
        'dropoff_point',
        'operational_notes',
        'prep_status',
        'status',
        'internal_notes',
        'contacted_at',
        'confirmed_at',
        'cancelled_at',
        'submitted_at',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'travel_date'          => 'date',
        'flexible_dates'       => 'boolean',
        'price_quoted'         => 'decimal:2',
        'payment_link_sent_at' => 'datetime',
        'paid_at'              => 'datetime',
        'contacted_at'         => 'datetime',
        'confirmed_at'         => 'datetime',
        'cancelled_at'         => 'datetime',
        'submitted_at'         => 'datetime',
    ];

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function guide(): BelongsTo
    {
        return $this->belongsTo(Guide::class);
    }

    public function stays(): HasMany
    {
        return $this->hasMany(InquiryStay::class)->orderBy('sort_order');
    }

    /**
     * Generate the next reference in the form INQ-YYYY-NNNNNN.
     *
     * Uses a per-year monotonic counter derived from the highest existing
     * reference for the current year. Safe under low-concurrency website
     * traffic; if volume grows we would replace this with a dedicated
     * sequence table.
     */
    public static function generateReference(?\DateTimeInterface $at = null): string
    {
        $at   = $at ?? now();
        $year = (int) $at->format('Y');

        $lastRef = self::where('reference', 'like', "INQ-{$year}-%")
            ->orderByDesc('id')
            ->value('reference');

        $next = 1;
        if ($lastRef && preg_match('/INQ-\d{4}-(\d+)/', $lastRef, $m)) {
            $next = ((int) $m[1]) + 1;
        }

        return sprintf('INQ-%d-%06d', $year, $next);
    }
}
