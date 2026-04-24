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

    // Single source of truth for "is this booking real enough to act on
    // suppliers for?". Both supplier-assign UIs (calendar slide-over, resource
    // form) and supplier-dispatch actions (Telegram notify) must agree — when
    // they drift, operators can assign but not dispatch (or vice versa).
    public function isDispatchable(): bool
    {
        return in_array($this->status, [
            self::STATUS_CONFIRMED,
            self::STATUS_AWAITING_PAYMENT,
        ], true);
    }

    public const PAYMENT_ONLINE      = 'online';
    public const PAYMENT_CASH        = 'cash';
    public const PAYMENT_CARD_OFFICE = 'card_office';

    public const PAYMENT_SPLIT_FULL    = 'full_online';
    public const PAYMENT_SPLIT_PARTIAL = 'partial';

    public const PAYMENT_SPLITS = [
        self::PAYMENT_SPLIT_FULL,
        self::PAYMENT_SPLIT_PARTIAL,
    ];

    // Octo requires a minimum reasonable amount. $10 prevents accidental
    // $1 links and keeps transaction fees from dominating the charge.
    public const MIN_ONLINE_USD = 10;

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
        'amount_online_usd',
        'amount_cash_usd',
        'payment_split',
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
        'driver_dispatched_at',
        'guide_cost',
        'guide_rate_id',
        'guide_cost_override',
        'guide_cost_override_reason',
        'guide_dispatched_at',
        'other_costs',
        'cost_notes',
        'created_by_user_id',
        'assigned_to_user_id',
        'closed_by_user_id',
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
        'driver_dispatched_at'      => 'datetime',
        'guide_dispatched_at'       => 'datetime',
        'submitted_at'              => 'datetime',
        'commission_rate'           => 'decimal:2',
        'commission_amount'         => 'decimal:2',
        'net_revenue'               => 'decimal:2',
        'amount_online_usd'         => 'decimal:2',
        'amount_cash_usd'           => 'decimal:2',
        'driver_cost'               => 'decimal:2',
        'driver_cost_override'      => 'boolean',
        'guide_cost'                => 'decimal:2',
        'guide_cost_override'       => 'boolean',
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

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by_user_id');
    }

    public function assignedToUser(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'assigned_to_user_id');
    }

    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'closed_by_user_id');
    }

    /**
     * Assign an operator to this inquiry if not already assigned.
     * First-touch ownership — does NOT overwrite an existing assignment.
     */
    public function assignIfUnowned(?int $userId): void
    {
        if (! $userId) {
            return;
        }
        if ($this->assigned_to_user_id) {
            return;
        }
        $this->assigned_to_user_id = $userId;
        $this->save();
    }

    public function guestPayments()
    {
        return $this->hasMany(GuestPayment::class)->where('status', 'recorded');
    }

    public function reminders()
    {
        return $this->hasMany(InquiryReminder::class)->orderBy('remind_at');
    }

    public function pendingReminders()
    {
        return $this->hasMany(InquiryReminder::class)
            ->where('status', 'pending')
            ->orderBy('remind_at');
    }

    public function totalReceived(): float
    {
        return (float) GuestPayment::where('booking_inquiry_id', $this->id)
            ->where('status', 'recorded')
            ->sum('amount');
    }

    public function outstanding(): float
    {
        return (float) ($this->price_quoted ?? 0) - $this->totalReceived();
    }

    /**
     * Auto-update paid_at + closed_by based on received payments.
     * Called by GuestPayment observer after any create/update/delete.
     */
    public function recomputePaymentStatus(): void
    {
        $quoted   = (float) ($this->price_quoted ?? 0);
        $received = $this->totalReceived();

        // Fully paid and no paid_at yet → set it
        if ($quoted > 0 && $received >= $quoted && ! $this->paid_at) {
            $this->paid_at = now();
            if ($this->status === self::STATUS_AWAITING_PAYMENT) {
                $this->status = self::STATUS_CONFIRMED;
            }
            // Phase 15.3 close attribution
            if (! $this->closed_by_user_id) {
                $this->closed_by_user_id = $this->assigned_to_user_id
                    ?? $this->created_by_user_id;
            }
            $this->saveQuietly();
            return;
        }

        // Refunded below threshold → unmark paid
        if ($quoted > 0 && $received < $quoted && $this->paid_at) {
            $this->paid_at = null;
            $this->saveQuietly();
        }
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

    public function guideRate(): BelongsTo
    {
        return $this->belongsTo(GuideRate::class);
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
