<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Yurt Camp Departure — supply-side scheduled trip.
 *
 * See PHASE_0_ARCHITECTURE_LOCK.md and PHASE_1_DEPARTURE_CORE_SPEC.md.
 *
 * Two modes:
 *   - group:   capacity > 1, multi-booking, publicly listed at status open|guaranteed
 *   - private: 1:1 with one BookingInquiry, never publicly listed
 *
 * Lifecycle is forward-only past `guaranteed` (G2). State transitions live in
 * action classes (G4); never call `$departure->update(['status' => ...])`.
 *
 * Seat math (seats_booked / seats_remaining / is_bookable) is computed here
 * and consumed everywhere else (G8 — no seat math in presentation layer).
 *
 * Type-conditional behavior (auto-cancel eligibility, public visibility,
 * threshold-field display) is delegated to App\Policies\DeparturePolicy.
 */
class Departure extends Model
{
    use HasFactory, SoftDeletes;

    public const TYPE_GROUP   = 'group';
    public const TYPE_PRIVATE = 'private';

    public const TYPES = [
        self::TYPE_GROUP,
        self::TYPE_PRIVATE,
    ];

    public const STATUS_DRAFT              = 'draft';
    public const STATUS_OPEN               = 'open';
    public const STATUS_GUARANTEED         = 'guaranteed';
    public const STATUS_CONFIRMED          = 'confirmed';
    public const STATUS_DEPARTED           = 'departed';
    public const STATUS_COMPLETED          = 'completed';
    public const STATUS_CANCELLED          = 'cancelled';
    public const STATUS_CANCELLED_MIN_PAX  = 'cancelled_min_pax';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_OPEN,
        self::STATUS_GUARANTEED,
        self::STATUS_CONFIRMED,
        self::STATUS_DEPARTED,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
        self::STATUS_CANCELLED_MIN_PAX,
    ];

    /** Statuses where the departure may take new bookings. */
    public const PUBLIC_STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_GUARANTEED,
    ];

    /** No further state transitions allowed from terminal statuses. */
    public const TERMINAL_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
        self::STATUS_CANCELLED_MIN_PAX,
    ];

    protected $fillable = [
        'reference',
        'tour_product_id',
        'tour_product_direction_id',
        'tour_type',
        'departure_date',
        'pickup_time',
        'pickup_point',
        'dropoff_point',
        'capacity_seats',
        'minimum_pax',
        'cutoff_at',
        'guarantee_at',
        'status',
        'price_per_person_usd_snapshot',
        'single_supplement_usd_snapshot',
        'currency',
        'driver_id',
        'guide_id',
        'vehicle_id',
        'operational_notes',
        'cancelled_reason',
        'opened_at',
        'guaranteed_at',
        'confirmed_at',
        'departed_at',
        'completed_at',
        'cancelled_at',
        'created_by_user_id',
    ];

    protected $casts = [
        'departure_date'                 => 'date',
        'capacity_seats'                 => 'integer',
        'minimum_pax'                    => 'integer',
        'cutoff_at'                      => 'datetime',
        'guarantee_at'                   => 'datetime',
        'price_per_person_usd_snapshot'  => 'decimal:2',
        'single_supplement_usd_snapshot' => 'decimal:2',
        'opened_at'                      => 'datetime',
        'guaranteed_at'                  => 'datetime',
        'confirmed_at'                   => 'datetime',
        'departed_at'                    => 'datetime',
        'completed_at'                   => 'datetime',
        'cancelled_at'                   => 'datetime',
    ];

    // ─── Relationships ─────────────────────────────────────────

    public function tourProduct(): BelongsTo
    {
        return $this->belongsTo(TourProduct::class);
    }

    public function direction(): BelongsTo
    {
        return $this->belongsTo(TourProductDirection::class, 'tour_product_direction_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function guide(): BelongsTo
    {
        return $this->belongsTo(Guide::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Car::class, 'vehicle_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(BookingInquiry::class);
    }

    /**
     * Bookings that count toward seats_booked. Cancelled and spam are
     * filtered out — they free their seats. See PHASE_0 §5.0 for the
     * full seat mutation matrix.
     */
    public function activeBookings(): HasMany
    {
        return $this->bookings()->whereNotIn('status', [
            BookingInquiry::STATUS_CANCELLED,
            BookingInquiry::STATUS_SPAM,
        ]);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    // ─── Computed accessors (read-only, no side effects) ──────

    /**
     * Sum of seats_held across all non-cancelled / non-spam bookings.
     * Source of truth for capacity decisions. G8: never recompute this
     * in presentation code — read this accessor.
     */
    public function getSeatsBookedAttribute(): int
    {
        return (int) $this->activeBookings()->sum('seats_held');
    }

    public function getSeatsRemainingAttribute(): int
    {
        return max(0, $this->capacity_seats - $this->seats_booked);
    }

    public function getIsBookableAttribute(): bool
    {
        if (! in_array($this->status, self::PUBLIC_STATUSES, true)) {
            return false;
        }
        if ($this->cutoff_at !== null && $this->cutoff_at->isPast()) {
            return false;
        }
        return $this->seats_remaining > 0;
    }

    public function isBookable(): bool
    {
        return $this->is_bookable;
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    // ─── Scopes ─────────────────────────────────────────────────

    /**
     * Status + cutoff filter ONLY. Does NOT check seats_remaining.
     * Use for admin lists where you'll evaluate seats per row.
     * For "actually has free seats" queries (public listings, sitemap),
     * use scopeBookableWithSeats().
     */
    public function scopeBookable(Builder $q): Builder
    {
        return $q->whereIn('status', self::PUBLIC_STATUSES)
            ->where(function (Builder $q) {
                $q->whereNull('cutoff_at')->orWhere('cutoff_at', '>', now());
            });
    }

    /**
     * Status + cutoff + seats_remaining > 0 — the strict bookable filter.
     * Subquery sums booking_inquiries.seats_held against capacity_seats.
     * Sold-out departures are excluded.
     */
    public function scopeBookableWithSeats(Builder $q): Builder
    {
        return $q->bookable()
            ->whereRaw(
                '(SELECT COALESCE(SUM(bi.seats_held), 0) FROM booking_inquiries bi '
                . 'WHERE bi.departure_id = departures.id '
                . 'AND bi.status NOT IN (?, ?)) < departures.capacity_seats',
                [BookingInquiry::STATUS_CANCELLED, BookingInquiry::STATUS_SPAM]
            );
    }

    public function scopePubliclyVisible(Builder $q): Builder
    {
        return $q->where('tour_type', self::TYPE_GROUP)
            ->whereIn('status', self::PUBLIC_STATUSES);
    }

    /**
     * Named seam for SEO sitemap / public listing endpoints. Initially
     * identical to publiclyVisible() — kept separate so SEO requirements
     * (only show ≥X days out, exclude sold-out) can drift via a single
     * scope edit instead of touching controllers.
     */
    public function scopeForSitemap(Builder $q): Builder
    {
        return $q->publiclyVisible();
    }

    public function scopeGroup(Builder $q): Builder
    {
        return $q->where('tour_type', self::TYPE_GROUP);
    }

    public function scopePrivate(Builder $q): Builder
    {
        return $q->where('tour_type', self::TYPE_PRIVATE);
    }

    public function scopeUpcoming(Builder $q): Builder
    {
        return $q->where('departure_date', '>=', today());
    }

    // ─── Reference generator ──────────────────────────────────

    /**
     * Sequential per-year reference: DEP-YYYY-NNNNNN. Includes soft-deleted
     * rows so deleted-then-recreated departures don't reuse references.
     */
    public static function generateReference(): string
    {
        $year = now()->year;
        $last = static::withTrashed()
            ->where('reference', 'like', "DEP-{$year}-%")
            ->orderByDesc('id')
            ->first();

        $sequence = $last
            ? ((int) substr($last->reference, -6)) + 1
            : 1;

        return sprintf('DEP-%d-%06d', $year, $sequence);
    }
}
