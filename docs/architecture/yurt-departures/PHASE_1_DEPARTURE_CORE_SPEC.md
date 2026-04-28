# Phase 1 — Departure Core Technical Spec

**Project:** Yurt Camp Departure Engine
**Status:** Draft for review (no code written yet)
**Date:** 2026-04-28
**Depends on:** `PHASE_0_ARCHITECTURE_LOCK.md` (must be approved first)
**Scope:** Exact migrations, models, action interfaces, Filament resource. No business logic implementations — interfaces only. Phase 2 will fill in the actions.

---

## 1. Migrations

Two migrations in this phase. Both additive, both fully reversible.

### 1.1 `2026_05_01_000001_create_departures_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('departures', function (Blueprint $t) {
            $t->id();

            // Identity
            $t->string('reference', 32)->unique();

            // Catalog linkage
            $t->foreignId('tour_product_id')
                ->constrained('tour_products')
                ->restrictOnDelete();
            $t->foreignId('tour_product_direction_id')
                ->nullable()
                ->constrained('tour_product_directions')
                ->nullOnDelete();
            $t->string('tour_type', 16)->index();  // 'group' | 'private'

            // Schedule
            $t->date('departure_date');
            $t->time('pickup_time')->nullable();
            $t->string('pickup_point', 255)->nullable();
            $t->string('dropoff_point', 255)->nullable();

            // Capacity & thresholds
            $t->unsignedSmallInteger('capacity_seats');
            $t->unsignedSmallInteger('minimum_pax');
            $t->timestamp('cutoff_at')->nullable();
            $t->timestamp('guarantee_at')->nullable();

            // Lifecycle
            $t->string('status', 32)->default('draft');

            // Pricing snapshot (immutable after creation)
            $t->decimal('price_per_person_usd_snapshot', 10, 2);
            $t->decimal('single_supplement_usd_snapshot', 10, 2)->nullable();
            $t->char('currency', 3)->default('USD');

            // Suppliers (nullable until assigned)
            $t->foreignId('driver_id')->nullable()
                ->constrained('drivers')->nullOnDelete();
            $t->foreignId('guide_id')->nullable()
                ->constrained('guides')->nullOnDelete();
            $t->foreignId('vehicle_id')->nullable()
                ->constrained('cars')->nullOnDelete();

            // Operational
            $t->text('operational_notes')->nullable();
            $t->string('cancelled_reason', 255)->nullable();

            // Audit timestamps for state transitions
            $t->timestamp('opened_at')->nullable();
            $t->timestamp('guaranteed_at')->nullable();
            $t->timestamp('confirmed_at')->nullable();
            $t->timestamp('departed_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();

            // Provenance
            $t->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $t->timestamps();
            $t->softDeletes();

            // Indexes for common query paths
            $t->index(['tour_product_id', 'departure_date']);
            $t->index(['status', 'departure_date']);
            $t->index(['tour_type', 'status']);
            $t->index('cutoff_at');
            $t->index('guarantee_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departures');
    }
};
```

**Rationale for design choices:**
- `restrictOnDelete()` on `tour_product_id`: a tour product cannot be deleted while departures exist. Operators must cancel all departures first. Prevents accidental data loss.
- `nullOnDelete()` on supplier FKs: supplier reassignment is normal; deletion shouldn't cascade.
- All state transition timestamps are `nullable()` and set by actions, never automatically. This gives a complete audit trail without requiring a separate audit table in Phase 1.
- `softDeletes()` because cancellation policy is "cancel, don't delete" — but we want a hard escape hatch for operator mistakes (test data, etc.).
- `currency` char(3) default 'USD' to match existing pattern in `booking_inquiries`.
- No `is_active` column — `status` is the source of truth for visibility.

### 1.2 `2026_05_01_000002_add_default_pickup_to_tour_product_directions.php` (NEW per Q3)

```php
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tour_product_directions', function (Blueprint $t) {
            $t->string('default_pickup_point', 255)
                ->nullable()
                ->after('end_city');
        });
    }

    public function down(): void
    {
        Schema::table('tour_product_directions', function (Blueprint $t) {
            $t->dropColumn('default_pickup_point');
        });
    }
};
```

Update `TourProductDirection::$fillable` to include `default_pickup_point`. Update `DirectionsRelationManager` form schema with new TextInput.

### 1.3 `2026_05_01_000003_add_departure_fields_to_booking_inquiries.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('booking_inquiries', function (Blueprint $t) {
            // Link to supply
            $t->foreignId('departure_id')
                ->nullable()
                ->after('tour_product_direction_id')
                ->constrained('departures')
                ->nullOnDelete();

            // Seat reservation
            $t->unsignedSmallInteger('seats_held')
                ->nullable()
                ->after('departure_id');

            // Hold timer (separate from payment_due_at — see Phase 0)
            $t->timestamp('seat_hold_expires_at')
                ->nullable()
                ->after('seats_held');

            // Payment timer (currently informal — formalize here)
            $t->timestamp('payment_due_at')
                ->nullable()
                ->after('seat_hold_expires_at');

            $t->index('departure_id');
            $t->index('seat_hold_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('booking_inquiries', function (Blueprint $t) {
            $t->dropForeign(['departure_id']);

            // Guard dropIndex calls — MySQL/MariaDB may have auto-removed
            // indexes when the FK was dropped, depending on version.
            // Postgres keeps them. Use hasIndex() to stay portable.
            if (Schema::hasIndex('booking_inquiries', 'booking_inquiries_departure_id_index')) {
                $t->dropIndex(['departure_id']);
            }
            if (Schema::hasIndex('booking_inquiries', 'booking_inquiries_seat_hold_expires_at_index')) {
                $t->dropIndex(['seat_hold_expires_at']);
            }

            $t->dropColumn([
                'departure_id',
                'seats_held',
                'seat_hold_expires_at',
                'payment_due_at',
            ]);
        });
    }
};
```

**Rationale:**
- All four columns are nullable. Existing `booking_inquiries` rows continue to work unchanged.
- `nullOnDelete()` on `departure_id`: if a departure is hard-deleted (escape hatch), bookings retain history but lose the FK.
- Index on `seat_hold_expires_at` for the Phase 7 hold-expiry cron query.
- `hasIndex()` guards prevent rollback failure on MySQL where FK drop also removes the auto-generated index.
- `single_supplement_usd_snapshot` (departures table) is captured for forward-compat. **Phase 1 captures the column; Phase 2 booking flow consumes it** when single-traveler bookings need the surcharge applied. No code in Phase 1 reads from this column.

---

## 2. Models

### 2.1 `app/Models/Departure.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Departure extends Model
{
    use HasFactory, SoftDeletes;

    // ─── Tour type ─────────────────────────────────────────────
    public const TYPE_GROUP = 'group';
    public const TYPE_PRIVATE = 'private';

    public const TYPES = [self::TYPE_GROUP, self::TYPE_PRIVATE];

    // ─── Status ────────────────────────────────────────────────
    public const STATUS_DRAFT = 'draft';
    public const STATUS_OPEN = 'open';
    public const STATUS_GUARANTEED = 'guaranteed';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_DEPARTED = 'departed';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_CANCELLED_MIN_PAX = 'cancelled_min_pax';

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

    // Statuses that are publicly listable
    public const PUBLIC_STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_GUARANTEED,
    ];

    // Terminal statuses (no further transitions allowed)
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
        'created_by_user_id',
    ];

    protected $casts = [
        'departure_date' => 'date',
        'pickup_time' => 'string',
        'capacity_seats' => 'integer',
        'minimum_pax' => 'integer',
        'cutoff_at' => 'datetime',
        'guarantee_at' => 'datetime',
        'price_per_person_usd_snapshot' => 'decimal:2',
        'single_supplement_usd_snapshot' => 'decimal:2',
        'opened_at' => 'datetime',
        'guaranteed_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'departed_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
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
        return in_array($this->status, [self::STATUS_OPEN, self::STATUS_GUARANTEED], true)
            && ($this->cutoff_at === null || $this->cutoff_at->isFuture())
            && $this->seats_remaining > 0;
    }

    public function getIsPubliclyVisibleAttribute(): bool
    {
        return $this->tour_type === self::TYPE_GROUP
            && in_array($this->status, self::PUBLIC_STATUSES, true);
    }

    public function isBookable(): bool
    {
        return $this->is_bookable;
    }

    // ─── Scopes ─────────────────────────────────────────────────

    /**
     * "Status + cutoff" check ONLY. Does NOT filter by seats_remaining.
     * Use for queries where you'll evaluate seats per-row (e.g., admin lists).
     * For "actually has seats" public listings, use scopeBookableWithSeats().
     */
    public function scopeBookable(Builder $q): Builder
    {
        return $q->whereIn('status', self::PUBLIC_STATUSES)
            ->where(function (Builder $q) {
                $q->whereNull('cutoff_at')->orWhere('cutoff_at', '>', now());
            });
    }

    /**
     * "Status + cutoff + seats remaining > 0" — the strict bookable filter.
     * Use for public listings (frontend, sitemap, marketing queries) where
     * sold-out departures must NOT appear.
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
     * Named seam for the public sitemap / SEO listings. Initially identical to
     * scopePubliclyVisible() — keep separate so SEO requirements drift (e.g.,
     * "only show ≥X days out", "exclude sold out") can change a single scope
     * instead of a controller. Per architect review 2026-04-28.
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
```

**Notes:**
- All status transitions are forbidden in the model — no `markGuaranteed()` etc. Those live in actions.
- All accessors are read-only; no side effects.
- `seats_booked` is a query, not a column. Simpler than a denormalized counter; performance is fine for <100 bookings/departure.
- Soft delete enabled but rarely used. Hard delete reserved for test data.

### 2.2 Update `app/Models/BookingInquiry.php`

Append to existing model:

```php
// Add to existing class

public function departure(): BelongsTo
{
    return $this->belongsTo(Departure::class);
}

// Helper: was this booking made against a scheduled departure?
public function isDepartureBooking(): bool
{
    return $this->departure_id !== null;
}

// Helper: is the seat hold still active?
public function hasActiveSeatHold(): bool
{
    return $this->seat_hold_expires_at !== null
        && $this->seat_hold_expires_at->isFuture()
        && $this->status === self::STATUS_AWAITING_PAYMENT;
}
```

Add `'departure_id', 'seats_held', 'seat_hold_expires_at', 'payment_due_at'` to `$fillable`.
Add casts: `'seat_hold_expires_at' => 'datetime'`, `'payment_due_at' => 'datetime'`.

---

## 3. Action class interfaces (Phase 1 deliverables — interfaces only)

Implementations land in Phase 2. Phase 1 ships the contracts so Filament resource can wire them up.

### 3.1 `app/Actions/Departures/CreateDepartureAction.php`

```php
namespace App\Actions\Departures;

use App\DataObjects\DepartureCreationData;
use App\Models\Departure;
use App\Models\User;

final class CreateDepartureAction
{
    /**
     * Create a new departure in DRAFT status.
     *
     * @throws InvalidDepartureConfiguration  if minimum_pax > capacity_seats
     * @throws TourProductPricingMissing      if tour has no matching price tier
     */
    public function execute(DepartureCreationData $data, ?User $actor = null): Departure;
}
```

### 3.2 `app/Actions/Departures/OpenDepartureAction.php`

```php
final class OpenDepartureAction
{
    public function __construct(
        private ValidateDepartureForOpenAction $validator,
    ) {}

    /**
     * Transition draft → open. Departure becomes publicly listable.
     * Runs Q7 validation gate; rejects if any pre-flight check fails.
     *
     * @throws InvalidDepartureTransition       if status !== draft
     * @throws InvalidDepartureConfiguration    if pre-flight validation fails
     *                                          (carries ValidationReport with issues)
     * @throws TourProductPricingMissing        if no matching group tier exists (Q1)
     */
    public function execute(Departure $departure, ?User $actor = null): Departure;
}
```

### 3.2.b `app/Actions/Departures/ValidateDepartureForOpenAction.php` (NEW per Q7)

Implemented as a rule registry to prevent god-class growth (per architect review). Each invariant is a separate `DepartureOpenRule` class. Adding a new check means adding a new class, not editing a method body.

```php
interface DepartureOpenRule
{
    /**
     * Return null if the rule passes, ValidationIssue if it fails.
     */
    public function check(Departure $departure): ?ValidationIssue;
}

final class ValidationIssue
{
    public function __construct(
        public readonly string $code,        // e.g., 'missing_pickup_point'
        public readonly string $message,     // operator-facing message
        public readonly string $severity,    // 'blocker' | 'warning'
        public readonly ?string $fixHint = null,
    ) {}
}

final class ValidationReport
{
    /** @var list<ValidationIssue> */
    public array $issues = [];

    public function isValid(): bool
    {
        return collect($this->issues)->where('severity', 'blocker')->isEmpty();
    }

    public function blockers(): array
    {
        return collect($this->issues)->where('severity', 'blocker')->values()->all();
    }

    public function warnings(): array
    {
        return collect($this->issues)->where('severity', 'warning')->values()->all();
    }
}

final class ValidateDepartureForOpenAction
{
    /**
     * @param iterable<DepartureOpenRule> $rules  Injected from a tagged service container binding
     */
    public function __construct(private iterable $rules) {}

    public function execute(Departure $departure): ValidationReport
    {
        $report = new ValidationReport();
        foreach ($this->rules as $rule) {
            if ($issue = $rule->check($departure)) {
                $report->issues[] = $issue;
            }
        }
        return $report;
    }
}
```

**Phase 1 ships these 6 rules** in `app/Actions/Departures/Rules/`:
- `HasTourProductRule` — checks `tour_product_id` set, references active TourProduct
- `HasScheduleRule` — checks `departure_date` future, `pickup_time` set, `pickup_point` non-empty
- `HasValidCapacityRule` — checks `capacity_seats >= 1`, `minimum_pax` valid for type
- `HasCutoffAndGuaranteeRule` — checks coherence of `cutoff_at` and `guarantee_at`
- `HasPriceSnapshotRule` — checks `price_per_person_usd_snapshot > 0`
- `HasMatchingPriceTierRule` — Q1 enforcement: matching `tour_price_tiers` row exists

**Service container binding:**
```php
$this->app->tag([
    HasTourProductRule::class,
    HasScheduleRule::class,
    HasValidCapacityRule::class,
    HasCutoffAndGuaranteeRule::class,
    HasPriceSnapshotRule::class,
    HasMatchingPriceTierRule::class,
], 'departure.open_rules');

$this->app->bind(ValidateDepartureForOpenAction::class, function ($app) {
    return new ValidateDepartureForOpenAction($app->tagged('departure.open_rules'));
});
```

**Future rules** (Phase 3+, examples — do NOT build now):
- `SupplierLeadTimeRule`, `BlackoutDateRule`, `MarketingTranslationsRule`

Each adds one class; the action class never grows.

### 3.2.c `app/Policies/DeparturePolicy.php` — central type-policy resolver (per architect review)

Prevents `tour_type === 'private'` checks from spreading across actions, scopes, and Filament. Single source of truth for type-conditional behavior.

```php
final class DeparturePolicy
{
    /**
     * Whether the auto-cancel cron may cancel this departure when
     * minimum_pax not met by guarantee_at.
     */
    public function allowsAutoCancel(Departure $departure): bool
    {
        // Group only — private departures match capacity to pax by definition.
        return $departure->tour_type === Departure::TYPE_GROUP;
    }

    /**
     * Whether minimum_pax is enforced (vs. auto-set to capacity).
     */
    public function requiresMinimumPax(Departure $departure): bool
    {
        return $departure->tour_type === Departure::TYPE_GROUP;
    }

    /**
     * Whether the departure may appear in public listings / sitemap.
     */
    public function isPubliclyListable(Departure $departure): bool
    {
        return $departure->tour_type === Departure::TYPE_GROUP
            && in_array($departure->status, Departure::PUBLIC_STATUSES, true);
    }

    /**
     * Whether minimum_pax + guarantee_at fields should appear on Filament form.
     */
    public function showsThresholdFields(Departure $departure): bool
    {
        return $departure->tour_type === Departure::TYPE_GROUP;
    }
}
```

**Usage rule:** Anywhere code is tempted to write `if ($departure->tour_type === Departure::TYPE_PRIVATE)`, use the policy instead. If a new question arises (`canHaveSingleSupplement`, `requiresPassport`, etc.), add a method to `DeparturePolicy`. The day a third type appears, this is the only edit site.

### 3.3 `app/Actions/Departures/MarkDepartureGuaranteedAction.php`

```php
final class MarkDepartureGuaranteedAction
{
    /**
     * Transition open → guaranteed. Forward-only.
     *
     * @throws InvalidDepartureTransition  if status !== open
     * @throws BelowMinimumPaxException    if seats_booked < minimum_pax
     */
    public function execute(Departure $departure, ?User $actor = null): Departure;
}
```

### 3.4 `app/Actions/Departures/ConfirmDepartureAction.php`

```php
final class ConfirmDepartureAction
{
    /**
     * Transition guaranteed → confirmed. Suppliers locked.
     *
     * @throws InvalidDepartureTransition  if status !== guaranteed
     * @throws SuppliersNotAssigned        if driver/guide/vehicle missing (per policy)
     */
    public function execute(Departure $departure, ?User $actor = null): Departure;
}
```

### 3.5 `app/Actions/Departures/CancelDepartureAction.php`

```php
final class CancelDepartureAction
{
    /**
     * Manual cancel from any non-terminal status.
     *
     * @throws InvalidDepartureTransition  if status is terminal
     */
    public function execute(
        Departure $departure,
        string $reason,
        ?User $actor = null,
    ): Departure;
}
```

### 3.6 `app/Actions/Departures/MarkDepartedAction.php`

```php
final class MarkDepartedAction
{
    /**
     * Transition confirmed → departed.
     *
     * @throws InvalidDepartureTransition  if status !== confirmed
     */
    public function execute(Departure $departure, ?User $actor = null): Departure;
}
```

### 3.7 `app/Actions/Departures/CompleteDepartureAction.php`

```php
final class CompleteDepartureAction
{
    /**
     * Transition departed → completed.
     *
     * @throws InvalidDepartureTransition  if status !== departed
     */
    public function execute(Departure $departure, ?User $actor = null): Departure;
}
```

### 3.8 Exception classes (`app/Exceptions/Departures/`)

```php
class InvalidDepartureTransition extends \DomainException {}
class InvalidDepartureConfiguration extends \DomainException {}
class TourProductPricingMissing extends \DomainException {}
class BelowMinimumPaxException extends \DomainException {}
class SuppliersNotAssigned extends \DomainException {}
class DepartureNotBookable extends \DomainException {}
class InsufficientSeats extends \DomainException
{
    public function __construct(
        public readonly int $seatsRequested,
        public readonly int $seatsRemaining,
    ) {
        parent::__construct(
            "Requested {$seatsRequested} seats, only {$seatsRemaining} remaining"
        );
    }
}
class BookingRequiresImmediatePayment extends \DomainException {}
```

---

## 4. DepartureResource (Filament)

`app/Filament/Resources/DepartureResource.php`

### 4.1 Navigation

- **Group:** Tours (sort -4, between TourProductResource and existing booking resources)
- **Icon:** `heroicon-o-calendar-days`
- **Label:** Departures
- **Plural label:** Departures
- **Badge:** count of `status = open` and `cutoff_at` within next 7 days (operator triage signal)

### 4.2 Form (create + edit)

```
┌─ Identity ────────────────────────────────────────────┐
│ reference (read-only, auto-generated)                  │
│ tour_product_id (Select, searchable, required)        │
│ tour_product_direction_id (Select, filtered by tour)  │
│ tour_type (Toggle group/private)                       │
└────────────────────────────────────────────────────────┘
┌─ Schedule ─────────────────────────────────────────────┐
│ departure_date (DatePicker, required, future-only)     │
│ pickup_time (TimePicker)                                │
│ pickup_point (TextInput)                                │
│ dropoff_point (TextInput, nullable)                     │
└─────────────────────────────────────────────────────────┘
┌─ Capacity & thresholds ────────────────────────────────┐
│ capacity_seats (NumberInput, default 12, min 1)        │
│ minimum_pax (NumberInput, default 4, ≤ capacity)        │
│ cutoff_at (DateTimePicker, default = departure_date - 48h)│
│ guarantee_at (DateTimePicker, default = departure_date - 72h)│
└─────────────────────────────────────────────────────────┘
┌─ Pricing snapshot ─────────────────────────────────────┐
│ price_per_person_usd_snapshot (NumberInput)             │
│   - auto-suggested from TourProduct::priceFor()         │
│   - operator can override                                │
│ single_supplement_usd_snapshot (NumberInput, optional)  │
│ currency (read-only, USD)                               │
└─────────────────────────────────────────────────────────┘
┌─ Suppliers (optional at creation) ──────────────────────┐
│ driver_id (Select, searchable, createOptionForm)        │
│ guide_id  (Select, searchable, createOptionForm)        │
│ vehicle_id (Select)                                      │
└─────────────────────────────────────────────────────────┘
┌─ Operational notes ────────────────────────────────────┐
│ operational_notes (Textarea)                            │
└─────────────────────────────────────────────────────────┘
```

**Visibility rules:**
- `tour_type === private` → hide `minimum_pax`, `guarantee_at` (auto-set to capacity / null). Resolved via `DeparturePolicy::showsThresholdFields()`.
- New record → status defaults to `draft`, no manual status edit.
- Edit on existing record → status read-only (use Actions to transition).

**Snapshot re-fetch on tour change (per code review):**
While `status === draft`, when operator changes `tour_product_id`, `tour_product_direction_id`, or `tour_type`:
- Form auto-refetches `TourProduct::priceFor($capacity_seats, $directionCode, $type)`.
- New value pre-fills `price_per_person_usd_snapshot`.
- Operator can override or accept the new suggestion.
- A "Resync price from catalog" button is also available on draft form for explicit refetch.

Once `status` leaves `draft`, the snapshot fields are locked (read-only display) per G6 mutability window.

**Field-editability matrix by status (per code review):**

| Field | draft | open | guaranteed | confirmed | departed | completed | cancelled* |
|---|---|---|---|---|---|---|---|
| tour_product_id | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| tour_product_direction_id | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| tour_type | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| departure_date | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| pickup_time | ✅ | ✅ | ✅ | ⚠️ ops-only | ❌ | ❌ | ❌ |
| pickup_point | ✅ | ✅ | ✅ | ⚠️ ops-only | ❌ | ❌ | ❌ |
| dropoff_point | ✅ | ✅ | ✅ | ⚠️ ops-only | ❌ | ❌ | ❌ |
| capacity_seats | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| minimum_pax | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| cutoff_at | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| guarantee_at | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| price_per_person_usd_snapshot | ✅ | ❌ (G6) | ❌ | ❌ | ❌ | ❌ | ❌ |
| single_supplement_usd_snapshot | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| driver_id | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| guide_id | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| vehicle_id | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| operational_notes | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ (audit) |
| cancelled_reason | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ |

\* Both `cancelled` and `cancelled_min_pax` follow the same row.

**⚠️ ops-only:** Filament shows the field but logs a warning entry to operational_notes when operator edits a confirmed-status pickup field (real ops emergency: car broke down, route closed). Audit trail captures who/when/why.

**Implementation:** Filament form `disabled()` callbacks consult an `app/Support/DepartureFieldEditability::canEdit($field, Departure $departure): bool` helper that reads the matrix above. Single source of truth.

### 4.3 Table

| Column | Sort | Filter | Notes |
|---|---|---|---|
| reference | - | - | Copyable badge |
| departure_date | ✓ | ✓ (date range) | Default sort desc |
| tour_product.title | - | ✓ (Select) | Truncate to 30 chars |
| direction.code | - | ✓ | Small badge |
| tour_type | - | ✓ (group/private) | Color: group=primary, private=gray |
| status | - | ✓ (multi-select) | Color-coded badge |
| seats_booked / capacity | - | - | Computed: "5/12" with progress bar |
| guarantee_at | ✓ | - | Relative time: "in 3 days" |
| driver.full_name | - | ✓ (assigned/unassigned) | - |

**Tabs (filters):**
- All
- Upcoming (departure_date >= today)
- This week
- Open (taking bookings)
- Guaranteed
- Needs review (status=open AND guarantee_at within 24h AND seats_booked < minimum_pax)
- Confirmed
- Past departures

### 4.4 Infolist (detail page)

```
┌─ Departure: DEP-2026-000123 ───────────────────────────┐
│ [status badge: GUARANTEED] [type badge: GROUP]          │
│ Yurt Camp Tour · sam-bukhara · 2026-05-10               │
└─────────────────────────────────────────────────────────┘

┌─ Schedule ───────────────────────────────────────────────┐
│ Date:        2026-05-10                                  │
│ Pickup:      08:00 — Gur Emir Mausoleum                  │
│ Dropoff:     Bukhara                                     │
└──────────────────────────────────────────────────────────┘

┌─ Capacity ──────────────────────────────────────────────┐
│ Booked:    █████░░░░░░  5 / 12 seats                    │
│ Minimum:   4 (✓ met)                                     │
│ Cutoff:    in 2 days (2026-05-08 08:00)                 │
│ Guarantee: passed (2026-05-07 08:00)                    │
└──────────────────────────────────────────────────────────┘

┌─ Revenue widget (Phase 1 — costs deferred to Phase 3) ──┐
│ Seats sold:        5 / 12                                │
│ Booked revenue:    $1,430  (sum of confirmed price_quoted)│
│ Pending revenue:   $572   (awaiting_payment)             │
│ Remaining seats:   7                                     │
│ [▓▓▓▓▓░░░░░░░] 42% filled                                │
│                                                           │
│ ℹ️ Cost rollup + margin analysis ships in Phase 3.       │
└──────────────────────────────────────────────────────────┘

┌─ Pricing ───────────────────────────────────────────────┐
│ Price per person:  $286                                  │
│ Single supplement: —                                     │
│ Currency: USD                                            │
└──────────────────────────────────────────────────────────┘

┌─ Suppliers ─────────────────────────────────────────────┐
│ Driver:   Akmal Karimov         (tap to WhatsApp)        │
│ Guide:    Dilshod Saidov        (tap to WhatsApp)        │
│ Vehicle:  Hyundai Staria · 12 seats                      │
└──────────────────────────────────────────────────────────┘

┌─ Bookings on this departure (5) ────────────────────────┐
│ INQ-2026-000543  John Smith    2 seats  $572  [paid]     │
│ INQ-2026-000545  Maria Garcia  1 seat   $286  [paid]     │
│ INQ-2026-000548  Wei Chen      2 seats  $572  [pending]  │
│ ...                                                       │
└──────────────────────────────────────────────────────────┘

┌─ Operational notes ─────────────────────────────────────┐
│ <text>                                                   │
└──────────────────────────────────────────────────────────┘

┌─ Lifecycle audit ───────────────────────────────────────┐
│ Created:        2026-04-20 by Bahodir                    │
│ Opened:         2026-04-20 14:30                         │
│ Guaranteed:     2026-05-02 09:15                         │
│ Confirmed:      —                                        │
│ Departed:       —                                        │
│ Completed:      —                                        │
│ Cancelled:      —                                        │
└──────────────────────────────────────────────────────────┘
```

### 4.5 Header actions (state machine triggers)

Each action wraps an action class call with try/catch + Filament notification.

| Action | Visible when | Calls | Disabled when |
|---|---|---|---|
| Open for booking | `status === draft` | `OpenDepartureAction` | Q7 validation report has blockers (tooltip lists missing items) |
| Mark guaranteed | `status === open` AND `seats_booked >= minimum_pax` | `MarkDepartureGuaranteedAction` | — |
| Confirm | `status === guaranteed` | `ConfirmDepartureAction` | — |
| Mark departed | `status === confirmed` AND `pickup_time` passed | `MarkDepartedAction` | — |
| Mark completed | `status === departed` | `CompleteDepartureAction` | — |
| Cancel | `status NOT IN (terminal)` | `CancelDepartureAction` (with reason form) | — |

### 4.5.b Pre-flight checklist panel (Phase 1, per Q7)

Above the action buttons on draft departures, show a checklist driven by `ValidateDepartureForOpenAction`:

```
┌─ Pre-flight checks ──────────────────────────────────────┐
│ ✅  Tour product set                                      │
│ ✅  Direction set                                         │
│ ✅  Pickup point set (auto-filled from direction)         │
│ ✅  Capacity 12, minimum pax 4 (valid)                   │
│ ✅  Cutoff 2026-05-08 08:00 (in 2 days)                  │
│ ✅  Guarantee 2026-05-07 08:00 (in 1 day)                │
│ ❌  Missing group price tier — add in Tour Catalog        │
│ ✅  Price snapshot $286 (auto-suggested, can override)    │
│                                                           │
│ [ Open for booking ]  ← greyed: 1 blocker                 │
└───────────────────────────────────────────────────────────┘
```

All actions show confirmation modal. All show success/failure notification. None bypass actions to write to DB directly.

### 4.6 RelationManagers

- `BookingsRelationManager` — read-only list of `BookingInquiry` rows where `departure_id = this`. Tap-through to existing `BookingInquiryResource` detail.

---

## 5. Test plan

### 5.1 Unit tests (`tests/Unit/`)

```
DepartureModelTest:
  - getSeatsBookedAttribute counts only active bookings
  - getSeatsRemainingAttribute = capacity - booked
  - isBookable returns false if cutoff passed
  - isBookable returns false if status=draft
  - isBookable returns false if seats_remaining = 0
  - isPubliclyVisible only true for group + open/guaranteed
  - generateReference produces sequential DEP-YYYY-NNNNNN
  - scopes (Bookable, PubliclyVisible, Group, Private, Upcoming)

DepartureStatusMachineTest (action-level, but uses fakes):
  - OpenDepartureAction: draft → open, sets opened_at
  - OpenDepartureAction throws InvalidDepartureTransition from non-draft
  - MarkDepartureGuaranteedAction: open → guaranteed, sets guaranteed_at
  - MarkDepartureGuaranteedAction throws BelowMinimumPaxException if seats < minimum
  - ConfirmDepartureAction: guaranteed → confirmed
  - All forbidden transitions throw InvalidDepartureTransition
```

### 5.2 Feature tests (`tests/Feature/`)

```
DepartureResourceTest:
  - operator can create draft departure via Filament
  - operator can open draft departure
  - operator can confirm guaranteed departure
  - operator cannot skip states (e.g. draft → confirmed direct)
  - cancelled departure is read-only
  - economics widget shows correct revenue + margin

ReservationConcurrencyTest:
  - two parallel reservations for last seat: exactly one succeeds
  - reserving on cancelled departure throws DepartureNotBookable
  - reserving past cutoff throws DepartureNotBookable
  - reserving on full departure throws InsufficientSeats
```

### 5.3 Migration tests

- Migration up + down cycle preserves existing data.
- Existing `BookingInquiryResource` admin page loads without errors after migration.
- Existing `TourCalendar` widget continues to function.

---

## 6. Decisions (LOCKED 2026-04-28)

All blocking decisions resolved. Implementations follow these rules.

### Q1. Auto-suggested price on departure creation — **LOCKED: force tiers before open**

**Rule:** A group departure cannot transition `draft → open` if no valid `tour_price_tiers` row exists for the (`tour_product_id`, `tour_product_direction_id`, `tour_type='group'`, `group_size <= capacity_seats`) combination.

**Implementation:**
- `CreateDepartureAction` allows draft creation without tier validation (operator can save partial).
- `OpenDepartureAction` enforces tier presence and throws `TourProductPricingMissing` if missing.
- Filament form: when operator picks tour + direction + type=group, attempt `TourProduct::priceFor($capacity_seats, $directionCode, 'group')`. If null, show inline warning:
  > ⚠️ No group pricing tier exists for this route. Add one in **Tour Catalog → Yurt Camp Tour → Price Tiers** before opening this departure.
- Open action is disabled (button greyed out) when validation fails. Tooltip explains why.

**Rationale:** Protects revenue, prevents silent SEO price drift, forces operators to configure pricing intentionally.

### Q2. Vehicle.number_seats vs Departure.capacity_seats — **LOCKED: soft warning, never block**

**Rule:** Sales capacity is independent of physical vehicle capacity. Operators may sell 10 seats on a 12-seat van (comfort) or 12 seats on a 14-seat van.

**Implementation:**
- No validation error if `capacity_seats > vehicle.number_seats`.
- Filament form shows soft warning:
  > ℹ️ Sales capacity (12) exceeds vehicle capacity (10). Confirm intentional.
- Warning is informational only; form submits regardless.

### Q3. `default_pickup_point` on directions — **LOCKED: add now**

**Rule:** Pickup point is route-level, not departure-level. A new column lives on `tour_product_directions`.

**Implementation:**
- New migration `2026_05_01_000003_add_default_pickup_to_tour_product_directions.php` adds `default_pickup_point` (string nullable, 255).
- `TourProductDirection` model fillable updated.
- `DirectionsRelationManager` form gets new field.
- `DepartureResource` form auto-fills `pickup_point` from selected direction's `default_pickup_point` on selection (operator can override).

**Migration:**
```php
Schema::table('tour_product_directions', function (Blueprint $t) {
    $t->string('default_pickup_point', 255)->nullable()->after('end_city');
});
```

### Q4. Seat hold cap — **LOCKED: cap to cutoff_at - 1h**

**Rule:** `seat_hold_expires_at = min(requested_hold, cutoff_at - 1 hour)`. If `now() >= cutoff_at - 1h` at reservation time, the booking requires immediate payment (no hold) or is rejected.

**Implementation (canonical — matches PHASE_0 §5.1 pseudocode):**
- `ReserveSeatsForDepartureAction` (Phase 2) computes:
  ```php
  $maxHoldUntil = $departure->cutoff_at?->copy()->subHour();
  $seatHoldExpiresAt = $maxHoldUntil
      ? now()->addHours(24)->min($maxHoldUntil)
      : now()->addHours(24);

  if ($maxHoldUntil && now()->gte($maxHoldUntil)) {
      throw new BookingRequiresImmediatePayment(
          'Booking is within cutoff window. Payment required immediately.'
      );
  }
  ```
- The condition `now() >= cutoff_at - 1h` is the single trigger. No additional 30-minute buffer.
- `now()->addHours(24)->min($maxHoldUntil)` returns the earlier of the two Carbon instances directly — no `Carbon::parse()` wrapper needed.
- New exception class: `BookingRequiresImmediatePayment` (added to Section 3.8 list).
- Phase 6 frontend: when this error returns, render checkout flow that goes straight to Octobank payment (no hold).

### Q5. Backfill existing inquiries — **LOCKED: no backfill**

**Rule:** Departures apply prospectively only. Existing `booking_inquiries` rows are never auto-linked to departures. Operator may manually link via Filament edit form if desired.

**Implementation:**
- Migration `2026_05_01_000002` adds `departure_id` as nullable; no UPDATE statement.
- No backfill seeder.
- `BookingInquiryResource` edit form allows operator to set `departure_id` manually (Phase 2).

**Rationale:** Preserves historical data integrity. Avoids fake/inferred departures from non-departure-aware inquiries.

### Q6. Economics widget scope in Phase 1 — **LOCKED: revenue-only in Phase 1, full economics in Phase 3**

**Phase 1 widget shows:**
- Seats sold (count of active bookings · sum of seats_held)
- Gross revenue (sum of `price_quoted` of confirmed bookings)
- Remaining seats
- Capacity bar visualization

**Phase 3 widget extends to add:**
- Driver cost (sum from inquiries OR fixed estimate)
- Guide cost
- Other costs
- Gross margin (revenue − costs)
- Margin %
- Break-even pax
- Above/below break-even badge

**Rationale:** Phase 1 establishes operational truth. Cost rollup logic may surface edge cases (overrides, multi-departure driver allocation) that drag scope. Defer to Phase 3 when operator dashboard is exercised in production.

### Q7. "Open departure" validation gate — **LOCKED (NEW): YES, comprehensive validation**

**Rule:** A draft departure may save partial. An `open` departure must satisfy ALL of the following invariants:

| Required for `open` | Field | Validation |
|---|---|---|
| Catalog | `tour_product_id` | Not null, references active TourProduct |
| Catalog | `tour_product_direction_id` | Not null (group); nullable for private — but recommended |
| Type | `tour_type` | In [group, private] |
| Schedule | `departure_date` | Not null, future-dated |
| Schedule | `pickup_time` | Not null |
| Schedule | `pickup_point` | Not null and non-empty |
| Capacity | `capacity_seats` | >= 1 |
| Capacity | `minimum_pax` | >= 1, <= capacity_seats (group only; private auto-set to capacity) |
| Cutoff | `cutoff_at` | Not null, between now() and `departure_date` |
| Guarantee | `guarantee_at` | Not null (group), null OK (private), <= `cutoff_at` |
| Pricing | `price_per_person_usd_snapshot` | > 0 |
| Pricing tier exists | matching `tour_price_tiers` row | Per Q1 rule |

**Implementation:**
- New action class `ValidateDepartureForOpenAction` returns `ValidationReport` (collection of issues).
- `OpenDepartureAction` calls it first; if any blockers, throws `InvalidDepartureConfiguration` with the report attached.
- Filament `DepartureResource` calls validator on detail page render and shows checklist:
  ```
  Pre-flight checks:
    ✅ Tour product set
    ✅ Direction set
    ✅ Pickup point set
    ✅ Capacity & minimum pax valid
    ❌ Pricing tier missing  ← blocks open
    ✅ Cutoff in future
  ```
- "Open for booking" button disabled until all checks pass.

**Rationale:** "Open" means public-facing. A public departure with missing pickup point or zero price is a customer-facing failure. The validation gate catches it before publication. Drafts remain operator-flexible.

---

## 7. Sign-off checklist

Before any code is written:

- [x] Phase 0 spec drafted + governance-locked
- [x] Q1–Q7 decisions resolved (Q7 added 2026-04-28)
- [x] Default values confirmed (capacity 12, min 4, cutoff 48h, guarantee 72h, USD)
- [x] Domain choice confirmed (aydarkulyurtcamp.com)
- [x] Independent code-reviewer pass complete (REQUEST CHANGES → 10 patches applied)
- [x] Independent software-architect pass complete (STRONG → DeparturePolicy + rule-objects added)
- [x] Seat Mutation Matrix locked (PHASE_0 §5.0)
- [x] Field-editability matrix locked (§4.2)
- [ ] Delta re-review on patches
- [ ] Operator runbook draft ready
- [ ] Staging deployment slot scheduled

**Governance rules locked (G1–G10):**
- G1: Drafts may be incomplete; Open departures must satisfy the Q7 pre-flight checklist
- G2: Forward-only state machine (no reversion from guaranteed)
- G3: Auto-cancel cron only fires on `open` status, never on `guaranteed`
- G4: All state transitions go through action classes (escape valve: artisan commands calling actions)
- G5: All system-state writes use `forceFill()->save()` (per memory `feedback_no_mass_assign_for_system_state.md`)
- G6: Pricing snapshotted at creation, mutable while draft, immutable thereafter
- G7: No backfill (escape valve: manual single-row Filament link)
- G8: No seat math in presentation layer
- G9: No in-place schema growth (multi-leg/multi-resource/bundles/postponement = future Phase X spec)
- G10: No localized customer-facing copy on Departure

**Updated coding time estimate (after delta patches 2026-04-28):**
- Migrations (3 files): 1.5 hours
- Models + scopes (incl. `scopeBookableWithSeats`, `scopeForSitemap`): 2 hours
- `DeparturePolicy` resolver: 30 min
- `DepartureOpenRule` interface + 6 rule classes + service binding: 2 hours
- `ValidateDepartureForOpenAction` + `ValidationReport`: 1 hour
- Action class skeletons + 8 exception classes: 2 hours
- `DepartureFieldEditability` helper: 30 min
- DepartureResource (form + table + infolist + actions + Q7 panel + editability matrix): 7 hours
- Tests (unit + concurrency + Q7 + policy + rules): 5 hours
- **Total Phase 1: ~3 working days**

(Was 2.5 days before delta patches. Added `DeparturePolicy`, rule-objects, `scopeBookableWithSeats`, and editability helper — ~4 hours of structured work that pays back across all subsequent phases.)

**Phase 2 (booking ↔ departure linkage + ReserveSeatsForDepartureAction with Q4 hold cap):** estimated 2 working days, separate spec.

---

## 8. Migration order summary (LOCKED)

```
Phase 1 migrations (3 files, additive, fully reversible):

  2026_05_01_000001_create_departures_table.php
    → adds new departures table

  2026_05_01_000002_add_default_pickup_to_tour_product_directions.php  (per Q3)
    → adds default_pickup_point column

  2026_05_01_000003_add_departure_fields_to_booking_inquiries.php
    → adds departure_id, seats_held, seat_hold_expires_at, payment_due_at
```

Run order matters: departures table must exist before booking_inquiries FK is added.
