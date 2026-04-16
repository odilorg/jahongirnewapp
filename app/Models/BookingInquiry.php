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

    // Tour type (private / group) — matches TourProduct::TYPE_*.
    // Stored on the inquiry so the quote calculator can resolve the
    // correct pricing tier even if the underlying tour product later
    // shifts its default type.
    public const TOUR_TYPE_PRIVATE = 'private';
    public const TOUR_TYPE_GROUP   = 'group';

    public const TOUR_TYPES = [
        self::TOUR_TYPE_PRIVATE,
        self::TOUR_TYPE_GROUP,
    ];

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

    // Booking sources — centralized for StoreBookingInquiryRequest, Filament
    // form, and Filament list filter. OTA_SOURCES gates the "external ref"
    // field visibility.
    public const SOURCE_WEBSITE  = 'website';
    public const SOURCE_WHATSAPP = 'whatsapp';
    public const SOURCE_TELEGRAM = 'telegram';
    public const SOURCE_PHONE    = 'phone';
    public const SOURCE_EMAIL    = 'email';
    public const SOURCE_WALK_IN  = 'walk_in';
    public const SOURCE_MANUAL   = 'manual';
    public const SOURCE_GYG      = 'gyg';
    public const SOURCE_VIATOR   = 'viator';

    public const SOURCES = [
        self::SOURCE_WEBSITE, self::SOURCE_WHATSAPP, self::SOURCE_TELEGRAM,
        self::SOURCE_PHONE, self::SOURCE_EMAIL, self::SOURCE_WALK_IN,
        self::SOURCE_MANUAL, self::SOURCE_GYG, self::SOURCE_VIATOR,
    ];

    public const SOURCE_LABELS = [
        self::SOURCE_WEBSITE  => 'Website form',
        self::SOURCE_WHATSAPP => 'WhatsApp',
        self::SOURCE_TELEGRAM => 'Telegram',
        self::SOURCE_PHONE    => 'Phone',
        self::SOURCE_EMAIL    => 'Email',
        self::SOURCE_WALK_IN  => 'Walk-in',
        self::SOURCE_MANUAL   => 'Manual',
        self::SOURCE_GYG      => 'GetYourGuide',
        self::SOURCE_VIATOR   => 'Viator',
    ];

    /** Sources that represent OTA / third-party bookings with external IDs. */
    public const OTA_SOURCES = [self::SOURCE_GYG, self::SOURCE_VIATOR];

    protected $fillable = [
        'reference',
        'source',
        'external_reference',
        'tour_slug',
        'tour_name_snapshot',
        'tour_product_id',
        'tour_product_direction_id',
        'tour_type',
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
        'commission_rate',
        'commission_amount',
        'net_revenue',
        'payment_method',
        'payment_link',
        'payment_link_sent_at',
        'paid_at',
        'octo_transaction_id',
        'booking_id',
        'driver_id',
        'guide_id',
        'driver_rate_id',
        'driver_cost',
        'driver_cost_override',
        'driver_cost_override_reason',
        'guide_cost',
        'other_costs',
        'cost_notes',
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
        'contacted_at'              => 'datetime',
        'confirmed_at'              => 'datetime',
        'cancelled_at'              => 'datetime',
        'review_request_sent_at'    => 'datetime',
        'hotel_request_sent_at'     => 'datetime',
        'payment_reminder_sent_at'  => 'datetime',
        'submitted_at'              => 'datetime',
        'commission_rate'           => 'decimal:2',
        'commission_amount'         => 'decimal:2',
        'net_revenue'               => 'decimal:2',
        'driver_cost'               => 'decimal:2',
        'driver_cost_override'      => 'boolean',
        'guide_cost'                => 'decimal:2',
        'other_costs'               => 'decimal:2',
    ];

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function guide(): BelongsTo
    {
        return $this->belongsTo(Guide::class);
    }

    /**
     * Catalog link — the tour PRODUCT this inquiry corresponds to.
     * Nullable: inquiries for tours not yet in the catalog, or
     * historical rows where no match was found at backfill time,
     * still work via tour_slug/tour_name_snapshot fallback.
     */
    public function tourProduct(): BelongsTo
    {
        return $this->belongsTo(TourProduct::class);
    }

    /**
     * Catalog link — the route variant (sam-bukhara, sam-sam, etc.)
     * within the tour product. Backfill leaves this null for any
     * tour product with multiple directions (ambiguous route).
     */
    public function tourProductDirection(): BelongsTo
    {
        return $this->belongsTo(TourProductDirection::class);
    }

    /**
     * What we actually receive after OTA commission.
     * Direct sources = price_quoted. OTA sources = net_revenue.
     */
    public function effectiveRevenue(): float
    {
        if ($this->net_revenue !== null) {
            return (float) $this->net_revenue;
        }

        return (float) ($this->price_quoted ?? 0);
    }

    public function driverRate(): BelongsTo
    {
        return $this->belongsTo(DriverRate::class);
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
