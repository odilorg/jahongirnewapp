# Phase 0 — Architecture Lock

**Project:** Yurt Camp Departure Engine
**Status:** Draft for review (no code written yet)
**Date:** 2026-04-28
**Scope:** Defines rules, semantics, and rehearsal procedure before any code is committed.

---

## 1. Locked decisions

| Decision | Value | Rationale |
|---|---|---|
| Booking model | Single `booking_inquiries` extended with `departure_id` | Reuse existing demand-side platform; no parallel booking system. |
| Supply model | New `departures` table | Missing entity in current schema; cleanly modeled. |
| Private tour handling | Departure created at booking time | Avoids parallel workflow; one architecture for both modes. |
| Hold semantics | `seat_hold_expires_at` separate from `payment_due_at` | Two crons, two purposes — no collision with existing payment-reminder cron. |
| Reserve transaction | `DB::transaction` + `Departure::lockForUpdate()` | Pessimistic lock; correct for low-volume seat booking. |
| Guarantee status | Forward-only (no reversion to `open`) | Once suppliers dispatched, lock the departure. |
| Default capacity | 12 seats | Operationally safe, fillable, scalable. |
| Default minimum pax | 4 | Achievable threshold, marketing-friendly. |
| Default cutoff | 48h before departure | Supplier + camp coordination window. |
| Default guarantee evaluation | 72h before departure | Marketing + operator intervention window. |
| Currency | USD primary, UZS display optional | Existing FX layer already handles dual display. |
| Domain | aydarkulyurtcamp.com (separate, branded "Operated by Jahongir Travel") | SEO authority + trust signal. |
| Frontend stack | Next.js 15 + TypeScript + Tailwind + shadcn/ui + next-intl | SEO + ISR + booking interactivity. |
| Frontend deployment | Same Jahongir VPS via PM2 + Nginx | Operational simplicity. |
| Build sequence | Operator truth before public frontend | Avoid frontend-driven schema chaos. |

---

## 2. Three-layer enforcement rules

All new code in this domain must obey strict layer separation. This is the discipline that prevents Filament-bloat — the #1 failure mode in Laravel ops platforms.

### 2.1 Data layer (`app/Models/`)

**Allowed:**
- Eloquent model definitions
- `$fillable`, `$casts`, `$dates`
- Relationships (`belongsTo`, `hasMany`, etc.)
- Query scopes (`scopeOpen`, `scopeBookable`, etc.)
- Read-only computed accessors (`getSeatsRemainingAttribute`)
- Constants (`STATUS_OPEN`, `STATUS_GUARANTEED`, etc.)

**Forbidden:**
- ❌ Status transition logic (`$departure->status = 'guaranteed'` inline)
- ❌ Side effects (no Telegram fires, no FX lookups, no DB writes outside save())
- ❌ Pricing logic
- ❌ Calls to other services
- ❌ Mutator side effects (setters that fire events)

**Example — correct:**
```php
class Departure extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_OPEN = 'open';
    public const STATUS_GUARANTEED = 'guaranteed';
    // ...

    public function bookings(): HasMany
    {
        return $this->hasMany(BookingInquiry::class);
    }

    public function getSeatsBookedAttribute(): int
    {
        return $this->bookings()
            ->whereNotIn('status', [BookingInquiry::STATUS_CANCELLED, BookingInquiry::STATUS_SPAM])
            ->sum('seats_held');
    }

    public function scopeBookable(Builder $q): Builder
    {
        // Note: this scope does NOT filter by seats_remaining. Callers that need
        // "actually has seats free" must additionally filter via has('activeBookings', '<', capacity_seats)
        // or call ->isBookable() per-row. See Phase 1 §2.1 for the bookable-with-seats variant.
        return $q->whereIn('status', [self::STATUS_OPEN, self::STATUS_GUARANTEED])
                 ->where(fn ($q) => $q->whereNull('cutoff_at')->orWhere('cutoff_at', '>', now()));
    }
}
```

**Example — forbidden in model:**
```php
// ❌ NEVER in model
public function markGuaranteed(): void
{
    $this->status = self::STATUS_GUARANTEED;
    $this->save();
    Telegram::notify('Departure guaranteed!');  // side effect — forbidden
    Mail::to($this->driver)->send(...);          // side effect — forbidden
}
```

**Doctrine — "No seat math in presentation layer":**
Seat counting, capacity validation, and remaining-seats computation MUST live exclusively in the data layer (model accessors) and business layer (action classes). Filament resources, HTTP controllers, API responses, and frontend code MUST NOT recompute seat math from raw bookings or capacity. They consume `$departure->seats_booked` / `$departure->seats_remaining` / `$departure->is_bookable` only. Duplicate seat math creates ghost bugs where two layers disagree about reality.

### 2.2 Business layer (`app/Actions/Departures/`, `app/Services/`)

**Allowed:**
- All state transitions
- All side effects (notifications, FX, payment links, audit logging)
- All concurrency control (`lockForUpdate`, transactions)
- Cross-model orchestration
- Validation of business invariants

**Forbidden:**
- ❌ HTTP request handling
- ❌ Filament form/table definitions
- ❌ View rendering

**Action class signature pattern:**
```php
final class MarkDepartureGuaranteedAction
{
    public function __construct(
        private OpsBotNotifier $ops,
        private AuditLogger $audit,
    ) {}

    public function execute(Departure $departure, ?User $actor = null): Departure
    {
        return DB::transaction(function () use ($departure, $actor) {
            // CORRECT: re-fetch with row lock. Calling lockForUpdate() on an
            // already-loaded model is a no-op — must be on the query builder.
            $departure = Departure::lockForUpdate()->findOrFail($departure->id);

            if ($departure->status !== Departure::STATUS_OPEN) {
                throw new InvalidDepartureTransition(
                    "Cannot mark guaranteed from status: {$departure->status}"
                );
            }

            $departure->forceFill([
                'status' => Departure::STATUS_GUARANTEED,
                'guaranteed_at' => now(),
            ])->save();

            $this->audit->record($departure, 'guaranteed', $actor);
            $this->ops->notifyGuaranteed($departure);

            return $departure;
        });
    }
}
```

**⚠️ Common mistake — DO NOT WRITE:**
```php
// ❌ WRONG — refresh() returns the model but lockForUpdate() on a loaded
// model is a no-op. This pattern does NOT take a row lock.
$departure->refresh()->lockForUpdate();

// ❌ WRONG — same reason.
$departure = $departure->fresh()->lockForUpdate();
```

**✅ CORRECT pattern (the only one allowed):**
```php
$departure = Departure::lockForUpdate()->findOrFail($departure->id);
```

The lock must be issued on the QueryBuilder before the SELECT, not on a hydrated model. Every Departure-locking site in the codebase must use this exact pattern.

**Why `forceFill()->save()` not `update()`:**
Per the project's hard-learned rule (memory `feedback_no_mass_assign_for_system_state.md`), system-state writes that bypass `$fillable` mistakes must use `forceFill()->save()`. `update()` silently dropping a field on a $fillable mismatch caused the 2026-04-26 hourly WhatsApp spam incident.

### 2.3 Presentation layer (`app/Filament/`, `app/Http/Controllers/`)

**Allowed:**
- Form/table/infolist definitions
- Request validation
- Calling actions
- Translating action results into HTTP responses or Filament notifications

**Forbidden:**
- ❌ Direct status changes (`$departure->update(['status' => ...])`)
- ❌ Direct database writes that bypass actions
- ❌ Side effects (no Telegram fires from controllers)
- ❌ Business validation (use FormRequest for input shape only; business invariants live in actions)

**Example — Filament resource calling an action:**
```php
Action::make('mark_guaranteed')
    ->label('Mark guaranteed')
    ->requiresConfirmation()
    ->action(function (Departure $record) {
        try {
            app(MarkDepartureGuaranteedAction::class)
                ->execute($record, auth()->user());

            Notification::make()->success()->title('Departure guaranteed')->send();
        } catch (InvalidDepartureTransition $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    });
```

---

## 3. Departure lifecycle state machine

```
                 ┌──────────┐
        create → │  draft   │
                 └────┬─────┘
                      │ open()
                      ▼
                 ┌──────────┐
       ┌─────────│   open   │──────────┐
       │         └────┬─────┘          │
       │              │ markGuaranteed │ cancel()
       │              ▼                │
       │         ┌──────────┐          │
       │  ┌──────│guaranteed│──────┐   │
       │  │      └────┬─────┘      │   │
       │  │           │ confirm()  │   │
       │  │           ▼            │   │
       │  │      ┌──────────┐      │   │
       │  │      │confirmed │      │   │
       │  │      └────┬─────┘      │   │
       │  │           │ depart()   │   │
       │  │           ▼            │   │
       │  │      ┌──────────┐      │   │
       │  │      │ departed │      │   │
       │  │      └────┬─────┘      │   │
       │  │           │ complete() │   │
       │  │           ▼            │   │
       │  │      ┌──────────┐      │   │
       │  │      │completed │      │   │
       │  │      └──────────┘      │   │
       │  │                        │   │
       │  └─→ cancelled ←──────────┘   │
       │                               │
       └─→ cancelled_min_pax (auto) ←──┘
```

### 3.1 Status definitions

| Status | Public visibility | Bookable | Description |
|---|---|---|---|
| `draft` | Hidden | No | Operator working on it; not yet listed. |
| `open` | Visible | Yes | Listed publicly, accepting bookings. |
| `guaranteed` | Visible | Yes | Reached `minimum_pax`; operator stops worrying. **Forward-only.** |
| `confirmed` | Visible | No (closed) | Operator dispatched suppliers; locked. |
| `departed` | Hidden | No | `pickup_time` has passed. |
| `completed` | Hidden | No | Post-tour; review window. |
| `cancelled` | Hidden | No | Operator-cancelled (manual). |
| `cancelled_min_pax` | Hidden | No | Auto-cancelled below `minimum_pax` at `guarantee_at`. |

### 3.2 Allowed transitions (forward-only with two cancellation outs)

| From | To | Method | Trigger |
|---|---|---|---|
| `draft` | `open` | `OpenDepartureAction` | Manual (operator) |
| `draft` | `cancelled` | `CancelDepartureAction` | Manual |
| `open` | `guaranteed` | `MarkDepartureGuaranteedAction` | Auto (cron) when seats_booked ≥ minimum_pax |
| `open` | `cancelled` | `CancelDepartureAction` | Manual |
| `open` | `cancelled_min_pax` | `CancelUnderfilledDepartureAction` | Auto (cron) when guarantee_at passes and minimum not met |
| `guaranteed` | `confirmed` | `ConfirmDepartureAction` | Manual (operator dispatched suppliers) |
| `guaranteed` | `cancelled` | `CancelDepartureAction` | Manual (force majeure) |
| `confirmed` | `departed` | `MarkDepartedAction` | Auto (cron) when pickup_time passes, OR manual |
| `confirmed` | `cancelled` | `CancelDepartureAction` | Manual (rare) |
| `departed` | `completed` | `CompleteDepartureAction` | Manual (operator confirms tour ran) |

### 3.3 Forbidden transitions (must throw `InvalidDepartureTransition`)

- ❌ `guaranteed → open` (cancellation reduces pax below minimum but operator already notified suppliers — keep guaranteed)
- ❌ `confirmed → guaranteed` (no going backwards once dispatched)
- ❌ `cancelled → anything` (terminal)
- ❌ `cancelled_min_pax → anything` (terminal)
- ❌ `completed → anything` (terminal)
- ❌ `departed → guaranteed` / `→ open` (no rewinding past departure)
- ❌ `draft → guaranteed` / `→ confirmed` directly (must pass through `open`)

### 3.4 The "guaranteed cancellation edge case"

**Scenario:** Departure hits 4 pax → `guaranteed`. One customer cancels → 3 pax. What happens?

**Answer:** Stay `guaranteed`. Operator decides:
1. Run anyway (absorb cost) → keep `guaranteed`, proceed to `confirmed`.
2. Cancel manually with operator-written apology → `cancelled` (not `cancelled_min_pax`).

The state machine does **not** auto-cancel a `guaranteed` departure that drops below `minimum_pax`. The auto-cancel cron only fires when status is still `open`.

---

## 4. Group vs private departure semantics

This is the most important rule to get right in Phase 1. Both modes share the same table; behavior differs by `tour_type`.

### 4.1 Group departure (`tour_type = 'group'`)

- **Created by:** Operator (via Filament) or template generator (Phase 10).
- **Created when:** Before any booking exists. Listed publicly when status = `open`.
- **Capacity:** Operator-set (default 12).
- **Minimum pax:** Operator-set (default 4).
- **Multiple bookings:** Yes — many `booking_inquiries` per departure.
- **Cancellation policy:** Auto-cancel allowed if status = `open` and minimum not met at `guarantee_at`.
- **Pricing:** Per-seat from `tour_price_tiers` where `tour_type = 'group'`, snapshotted to `price_per_person_usd_snapshot` at departure creation.

### 4.2 Private departure (`tour_type = 'private'`)

- **Created by:** Booking action (when website booking arrives without a `departure_id`, OR operator manually creates from inquiry).
- **Created when:** At booking time. Never publicly listed.
- **Capacity:** = pax of the booking inquiry that created it.
- **Minimum pax:** = capacity (always met by definition).
- **Multiple bookings:** No — exactly one `booking_inquiry` per private departure (1:1).
- **Cancellation policy:** Manual only. Auto-cancel cron skips `tour_type = 'private'`.
- **Pricing:** From `tour_price_tiers` where `tour_type = 'private'`, snapshotted at creation.
- **Public visibility:** Always `false` regardless of status.

### 4.3 Why the same table

Alternative: separate `private_bookings` and `group_departures` tables.
**Rejected because:**
- Operator dashboard would need two query paths.
- Reports (revenue, supplier dispatch) would need union queries.
- The data shape is identical: a date, a route, a capacity, a status, suppliers.
- Filament resource forking doubles maintenance.

**The single-table approach with `tour_type` discriminator is correct.** Different lifecycle rules are enforced in actions, not in schema.

### 4.4 Operator UX implication

The Filament `DepartureResource` must clearly distinguish private from group:
- Tab filter: All / Group / Private
- Color-coded status badges (group = green/amber, private = neutral)
- Private departure detail page hides "minimum pax" field (always = capacity)
- Group departure list shows "X/12 booked" badge; private shows "private — booked"

---

## 5. Seat reservation transaction design

### 5.0 Seat Mutation Matrix (LOCKED — implementation reference)

**Master rule:** *If an action can change the effective `seats_booked` value of a departure, it MUST acquire `Departure::lockForUpdate()->findOrFail($id)` first, inside the same `DB::transaction`.*

This matrix lists every code path that touches departure seat math. Every implementer must consult it.

| Action / Event | Touches a BookingInquiry with `departure_id`? | Changes `seats_booked`? | Requires departure lock? |
|---|---|---|---|
| `ReserveSeatsForDepartureAction` (Phase 2) | Creates a new inquiry | Yes (+seats_held) | ✅ YES |
| `CancelInquiryAction` (operator manual cancel) | Yes — status → cancelled | Yes (−seats_held) | ✅ YES |
| `MarkInquirySpamAction` | Yes — status → spam | Yes (−seats_held) | ✅ YES |
| Octobank webhook: payment success | Yes — status awaiting_payment → confirmed | No (status counts as active in both) | ❌ NO (inquiry-level lock only) |
| Octobank webhook: payment failure → inquiry stays | Yes — no status change | No | ❌ NO |
| Octobank webhook: payment failure → inquiry cancelled | Yes — status → cancelled | Yes (−seats_held) | ✅ YES |
| `ExpireUnpaidSeatHoldsAction` (Phase 7 cron) | Yes — awaiting_payment → cancelled | Yes (−seats_held) | ✅ YES (per inquiry) |
| Operator edits `seats_held` on existing inquiry | Yes | Yes (delta) | ✅ YES |
| Operator unlinks departure_id from inquiry | Yes — departure_id set to null | Yes (−seats_held from old departure) | ✅ YES |
| Operator links departure_id to existing inquiry | Yes — departure_id set | Yes (+seats_held on new departure) | ✅ YES |
| Departure status change: open → guaranteed | No (read-only count check) | No | ❌ NO |
| Departure status change: any → cancelled | No (bookings unaffected by departure cancel) | No | ❌ NO* |
| `MarkDepartureGuaranteedAction` | No | No | ⚠️ YES (locks departure status, not seats) |
| `OpenDepartureAction` | No | No | ⚠️ YES (locks for status transition) |
| Operator edits `operational_notes` | No | No | ❌ NO |
| Operator edits `pickup_time` / `pickup_point` | No | No | ❌ NO |
| Operator edits `capacity_seats` (only allowed in draft) | No (capacity, not booked) | No | ❌ NO** |

\* When a departure is cancelled, its bookings are NOT auto-cancelled (operators decide each one). The booking cancellations that follow individually take departure locks.

\** Editing capacity_seats does not change `seats_booked` but DOES change `seats_remaining`. Restrict capacity edits to `status=draft` only — once a departure is open, capacity is frozen. Validation enforced in Filament form schema.

**Corollary for callback handlers:** The Octobank callback handler's existing logic (per memory `project_booking_inquiries_system.md`) flips `awaiting_payment → confirmed`. This does NOT change `seats_booked` because both states count as active. **However**, if the same handler ever cancels an inquiry (e.g., refund webhook, admin-triggered cancellation), it MUST take the departure lock. Phase 2 implementation must audit this path.

**Corollary for tinker / artisan commands:** Any data-fix script that modifies `booking_inquiries.status`, `booking_inquiries.seats_held`, or `booking_inquiries.departure_id` MUST go through the appropriate action class (per G4). Raw `DB::table()->update()` is forbidden for these fields, even in artisan commands.

### 5.1 Pseudocode

```php
final class ReserveSeatsForDepartureAction
{
    public function __construct(
        private OctoPaymentService $octo,
        private BookingInquiryNotifier $notifier,
    ) {}

    /**
     * @throws DepartureNotBookable
     * @throws InsufficientSeats
     * @throws InvalidArgumentException
     */
    public function execute(
        Departure $departure,
        InquiryData $data,        // value object: name, email, phone, pax, etc.
        int $seatsRequested,
    ): BookingInquiry {
        if ($seatsRequested < 1) {
            throw new InvalidArgumentException('seats_requested must be >= 1');
        }

        return DB::transaction(function () use ($departure, $data, $seatsRequested) {
            // 1. Lock the departure row
            $departure = Departure::lockForUpdate()->findOrFail($departure->id);

            // 2. Validate bookable
            if (! $departure->isBookable()) {
                throw new DepartureNotBookable(
                    "Departure status: {$departure->status}, cutoff: {$departure->cutoff_at}"
                );
            }

            // 3. Compute current seats booked.
            // CONCURRENCY NOTE: $departure->seats_booked is an accessor that runs a SELECT
            // against booking_inquiries. The departure row lock SERIALIZES concurrent
            // ReserveSeatsForDepartureAction calls — only one transaction holds the row
            // lock at a time, so the SUM result is always consistent for the holder.
            // The lock does NOT make the SUM see uncommitted writes from OTHER transactions
            // (it sees committed data per READ COMMITTED). Correctness comes from
            // serialization, not visibility. Every other code path that mutates effective
            // seats_booked must take the same lock — see Section 5.0 Seat Mutation Matrix.
            $seatsBooked = $departure->seats_booked;
            $seatsRemaining = $departure->capacity_seats - $seatsBooked;

            if ($seatsRemaining < $seatsRequested) {
                throw new InsufficientSeats(
                    seatsRequested: $seatsRequested,
                    seatsRemaining: $seatsRemaining,
                );
            }

            // 4. Snapshot price at booking time
            $priceTotal = $departure->price_per_person_usd_snapshot * $seatsRequested;

            // 5. Compute seat-hold expiry per Q4 cap rule.
            //    seat_hold_expires_at = min(now() + 24h, cutoff_at - 1h)
            //    If now() >= cutoff_at - 1h, throw BookingRequiresImmediatePayment
            //    so frontend renders direct-to-payment checkout instead.
            $maxHoldUntil = $departure->cutoff_at?->copy()->subHour();
            $seatHoldExpiresAt = $maxHoldUntil
                ? now()->addHours(24)->min($maxHoldUntil)  // Carbon->min() returns earlier instance
                : now()->addHours(24);

            if ($maxHoldUntil && now()->gte($maxHoldUntil)) {
                throw new BookingRequiresImmediatePayment(
                    'Booking is within cutoff window. Payment required immediately.'
                );
            }

            // 6. Create booking inquiry
            $inquiry = BookingInquiry::create([
                ...$data->toArray(),
                'departure_id' => $departure->id,
                'tour_product_id' => $departure->tour_product_id,
                'tour_product_direction_id' => $departure->tour_product_direction_id,
                'tour_type' => $departure->tour_type,
                'tour_slug' => $departure->tourProduct->slug,
                'tour_name_snapshot' => $departure->tourProduct->title,
                'travel_date' => $departure->departure_date,
                'pickup_time' => $departure->pickup_time,
                'pickup_point' => $departure->pickup_point,
                'seats_held' => $seatsRequested,
                'price_quoted' => $priceTotal,
                'currency' => $departure->currency,
                'status' => BookingInquiry::STATUS_AWAITING_PAYMENT,
                'seat_hold_expires_at' => $seatHoldExpiresAt,
                'payment_due_at' => $seatHoldExpiresAt,  // initial value; reminder cron may use shorter
                'submitted_at' => now(),
                'reference' => BookingInquiry::generateReference(),
            ]);

            return $inquiry;
        });
        // Lock released at transaction commit
    }
}
```

### 5.2 Why `lockForUpdate()` not optimistic locking

- **Volume:** <100 bookings/day. Pessimistic lock blocks for ~50ms — invisible to users.
- **Correctness:** Optimistic locking requires retry logic; we don't want to expose retries to the booking UX.
- **Simplicity:** Pessimistic = one less failure mode to test.

### 5.3 Concurrent test plan

Phase 1 ships with this integration test:

```php
test('two parallel reservations for the last seat do not overbook', function () {
    $departure = Departure::factory()->create([
        'capacity_seats' => 1,
        'tour_type' => Departure::TYPE_GROUP,
        'status' => Departure::STATUS_OPEN,
    ]);

    $action = app(ReserveSeatsForDepartureAction::class);

    // Fire two reservations using parallel processes
    $results = parallel(2, fn () => $action->execute($departure, $fakeData, 1));

    // Exactly one should succeed
    expect($results->successCount())->toBe(1);
    expect($results->failures())->toHaveLength(1);
    expect($results->failures()[0])->toBeInstanceOf(InsufficientSeats::class);
});
```

(Implementation detail: parallel processes via `pcntl_fork` or DB-backed barrier; fixture in test helper.)

---

## 6. Cron jobs (Phase 7 preview, to be defined now)

| Cron | Frequency | Action | Fires for |
|---|---|---|---|
| `departures:expire-holds` | Every 15 min | `ExpireUnpaidSeatHoldsAction` | inquiries where status=awaiting_payment AND seat_hold_expires_at < now() |
| `departures:evaluate-guarantees` | Hourly | `EvaluateDepartureGuaranteeAction` | departures where status=open AND seats_booked >= minimum_pax |
| `departures:auto-cancel-underfilled` | Hourly | `CancelUnderfilledDepartureAction` | departures where status=open AND guarantee_at < now() AND seats_booked < minimum_pax |
| `departures:mark-departed` | Hourly | `MarkDepartedAction` | departures where status=confirmed AND pickup_time < now() |

**Existing crons to coordinate with:**
- `inquiry:send-payment-reminders` (hourly) — must not double-fire with `expire-holds`. Resolution: reminder uses `payment_due_at`, expiry uses `seat_hold_expires_at`. They are intentionally different timestamps, even if Phase 2 sets them to the same value initially.

---

## 7. Migration rollback checklist

Every Phase 1 migration must satisfy:

- [ ] `down()` method drops cleanly without data loss for unrelated tables
- [ ] Foreign keys use `nullOnDelete()` not `cascadeOnDelete()` (except where data is owned 1:1, e.g., travelers → booking_inquiries)
- [ ] Adding columns to `booking_inquiries`: all new columns are nullable, no defaults that change behavior
- [ ] `DepartureResource` Filament resource handles `null` for all new columns gracefully
- [ ] No code path reads from `departures` table outside of new code (existing code unaware of departures table)

**Rollback test procedure:**
1. Backup staging DB.
2. Run `php artisan migrate` — confirm new tables/columns appear.
3. Verify `BookingInquiryResource` admin page still loads with zero rows in new columns.
4. Verify existing booking_inquiries CRUD still works (create test inquiry without departure_id).
5. Run `php artisan migrate:rollback --step=N` (where N = new Phase 1 migrations).
6. Confirm `booking_inquiries` is back to pre-Phase-1 schema.
7. Confirm Filament admin still works.
8. Re-apply migrations. Confirm idempotence.

---

## 8. Staging deployment rehearsal procedure

Before any Phase 1 code touches production:

1. **Local development:** All migrations + models + tests pass on developer machine.
2. **Local staging branch:** Push to `feature/yurt-departures-phase-1`.
3. **Staging deploy:** Use `scripts/deploy.sh` (per memory `feedback_production_deploy_discipline.md`). Never ad-hoc.
4. **Migration rehearsal on staging:**
   - Backup staging DB.
   - Run migrations.
   - Smoke-test Filament admin.
   - Create 3 test departures (1 draft, 1 open group, 1 private) via Filament.
   - Manually link an existing inquiry to a departure via tinker.
   - Verify TourCalendar still groups correctly.
   - Run `php artisan migrate:rollback`. Confirm clean.
   - Run migrations again. Confirm idempotent.
5. **Operator walkthrough on staging** (before prod): 1-hour session with operator(s) showing:
   - How to create a departure.
   - How a booking links to a departure.
   - How to mark departure guaranteed/confirmed/departed/completed.
   - What auto-cancel will do (in Phase 7) and what it will NOT do.
   - When to use group vs private (and that private auto-creates from inquiry).
6. **Production deploy:** Only after operator sign-off. Use `scripts/deploy.sh`. Tag release per memory `feedback_jahongirnewapp_release_tag_from_main.md` (cut from current origin/main HEAD).
7. **Post-deploy verification:** TodoWrite checklist:
   - PM2 status all green
   - Filament admin loads
   - `DepartureResource` page loads
   - Existing inquiry pipeline still works (no regressions)
   - Backup confirmed before-migration
   - Rollback path documented

---

## 9. Operator workflow draft (1-hour walkthrough doc)

This is the seed for the operator runbook. Final version delivered before Phase 1 prod deploy.

### 9.1 Two booking modes

```
Customer books on aydarkulyurtcamp.com → has departure_id (group)
Customer books on jahongir-travel.uz   → no departure_id (private, date-flexible)
Customer messages on WhatsApp/phone    → operator creates inquiry, attaches departure_id (or not)
```

### 9.2 Daily operator routine

**Morning (09:00):**
1. Check Filament dashboard: today's tours (existing widget, unchanged).
2. Check new "Upcoming departures" widget: next 7 days, sorted by guarantee_at proximity.
3. Triage `open` departures with low pax: market harder, suggest private upsell, or accept cancellation.

**Throughout day:**
- New website inquiries → Filament admin → if matches an open departure date, attach `departure_id`. Otherwise treat as private (auto-create departure on confirmation).

**Evening (19:00, existing recap):**
- Tomorrow's confirmed departures with supplier assignment.
- Any departures hitting guarantee_at in next 24h that are below minimum.

### 9.3 The "should I run this departure?" decision

The economics widget on the Departure detail page shows:
- Booked revenue (sum of `price_quoted` of confirmed inquiries on this departure)
- Driver cost (`driver_cost` from inquiries or fixed estimate)
- Guide cost
- Other costs (camp, transport, etc.)
- Gross margin (revenue - costs)
- Break-even pax (cost / price_per_person)
- Current pax / minimum pax / capacity

This is the operator's go/no-go signal. Below break-even pax → cancel manually before guarantee_at, send personalized apology, suggest private upsell.

---

## 10. Governance rules (LOCKED 2026-04-28)

In addition to the decisions in §1, the following governance rules are non-negotiable for the Departure domain:

### G1. "Drafts may be incomplete. Open departures must be valid."

A draft departure can be saved with partial data. An open departure must satisfy the **Q7 pre-flight validation gate** (defined in PHASE_1_DEPARTURE_CORE_SPEC.md §6 Q7):

- `tour_product_id`, `tour_product_direction_id`, `tour_type` set
- `departure_date` future
- `pickup_time`, `pickup_point` set
- `capacity_seats >= 1`, `minimum_pax 1..capacity` (group)
- `cutoff_at` not null and in future
- `price_per_person_usd_snapshot > 0`
- Matching `tour_price_tiers` row exists for (tour, direction, type, group_size <= capacity)

The `OpenDepartureAction` enforces this. Filament UI shows a pre-flight checklist on draft detail pages.

### G2. Forward-only state machine

Once a departure reaches `guaranteed`, it never reverts. Suppliers have been notified; reverting would create operational chaos. Cancellation from `guaranteed` goes to `cancelled` directly (with operator reason), bypassing `open`.

### G3. Auto-cancel only fires on `open` status

The Phase 7 auto-cancel cron (`departures:auto-cancel-underfilled`) checks `status = open AND guarantee_at < now() AND seats_booked < minimum_pax`. It never touches `guaranteed`, `confirmed`, or any other status. A guaranteed departure that drops below minimum is operator's manual decision.

### G4. All state transitions go through action classes

Direct DB writes that change `status` are forbidden in:
- Filament resource code
- HTTP controllers
- Webhook handlers
- Tests (except action class tests)

Even seeders and tinker should call action classes. The only exception: factories may set status directly to construct test fixtures.

**Sanctioned escape valve for data fixes:** Operator-error correction or test-data cleanup must be done via a one-off artisan command in `app/Console/Commands/Departures/` that calls action classes internally. Tinker is for read-only investigation. Raw `DB::table('departures')->update(...)` is forbidden for status, timestamps, or any lifecycle field. If a fix genuinely cannot be expressed via an action, that's a signal the action class is missing a method — add it, don't bypass it.

### G5. System-state writes use `forceFill()->save()`, never `update()`

Per memory `feedback_no_mass_assign_for_system_state.md`. Status, timestamps, financial fields, and lifecycle markers must use `forceFill()->save()` to avoid silent `$fillable` mismatches. The 2026-04-26 hourly WhatsApp spam incident was caused by exactly this kind of mistake.

### G6. Pricing is snapshotted at departure creation, never recomputed live (with draft mutability window)

When a departure is created, `price_per_person_usd_snapshot` is captured from `TourProduct::priceFor()`. After creation, the catalog can change tier prices freely — the departure preserves its booking-time price. When a customer books, that snapshot is copied again into `booking_inquiries.price_quoted` (already current behavior). Never call `priceFor()` on read paths.

**Mutability window:**
- While `status = draft` → snapshot is **mutable**. Operator may change `tour_product_id`, `tour_product_direction_id`, `tour_type`, `capacity_seats`, OR press a "Resync price from catalog" button to re-fetch from `priceFor()`. Operator may also override the snapshot value manually.
- On first transition out of draft (`OpenDepartureAction` succeeds) → snapshot becomes **immutable**. Filament form locks the field. Any future "the price is wrong" correction requires cancelling the departure and creating a new one.

**Q1 vs G6 interaction (clarification):** Q1 enforces that a matching `tour_price_tiers` row exists at OPEN time. G6 protects the *value* in `price_per_person_usd_snapshot` from drifting. If a tier's price changes between draft creation and open, the snapshot reflects the draft-time price — Q1 only verifies tier *existence*, not value match. This is intentional. Customers who saw $280 in marketing get $280, even if the catalog price moved to $320.

**Snapshot truth corollary (for booking_inquiries.price_quoted):** `booking_inquiries.price_quoted` is the customer-facing total at booking time and is always truth for revenue reporting. `departures.price_per_person_usd_snapshot × seats_held` may differ from `price_quoted` if operator manually adjusts a specific booking. When the two diverge, `price_quoted` wins.

### G7. Backfill is forbidden (with one sanctioned manual link)

Existing `booking_inquiries` rows are never auto-linked to departures. No seeder, no migration UPDATE, no bulk script — even for migration cleanup or "I just want to roll up these 30 inquiries to test the dashboard."

**Sanctioned exception:** Operators may manually link a single existing inquiry to a departure via the Filament edit form (Phase 2 wires this up). This is the *only* sanctioned backfill path. The action goes through `LinkInquiryToDepartureAction` (locks the departure, validates seats, snapshots context).

### G8. No seat math in presentation layer

Filament resources, HTTP controllers, API responses, and frontend code MUST NOT recompute seat math from raw bookings or capacity. They consume `$departure->seats_booked` / `$departure->seats_remaining` / `$departure->is_bookable` only. Duplicate seat math creates ghost bugs where two layers disagree about reality. If you find yourself writing `count($departure->bookings)` or `$capacity - $sumOfSeats` outside `app/Models/` or `app/Actions/`, stop — call the accessor instead.

### G9. Architectural limits (no in-place schema growth)

The Departure schema is locked to:
- 1 product per departure (`tour_product_id` scalar FK)
- 1 route per departure (`tour_product_direction_id` scalar FK)
- 1 capacity dimension (`capacity_seats` scalar int)
- 1 supplier per role (`driver_id`, `guide_id`, `vehicle_id` scalar FKs)
- 1 day per departure (`departure_date` single date)

The following requirements are NOT served by adding columns to `departures`. They require a new spec phase:
- **Multi-leg routes** (Sam → Yurt → Bukhara as one sellable unit with different pickups per leg) → future `departure_legs` table
- **Multi-resource capacity** (16 yurt beds vs 12 vehicle seats) → future capacity-by-resource model
- **Bundled products** (yurt camp + city tour combo) → future `departure_bundles` or product-bundle rework
- **Multi-supplier-per-departure** (driver swap mid-route, guide rotation across days) → future `departure_supplier_assignments` pivot
- **Postponement** (move guaranteed departure to a different date keeping bookings) → not supported in v1. Operator workflow: cancel guaranteed departure with reason 'postponed', create new departure, manually relink bookings via Filament (per G7 sanctioned exception).
- **Multi-day departures with per-day operations** → currently `departure_date` is start; duration comes from TourProduct. If per-day driver/pickup/yurt assignment becomes real, that is also a `departure_legs` extension.

Anyone tempted to add a `leg_2_pickup_point` or `vehicle_id_day_2` column should propose a Phase X spec instead. The schema is intentionally narrow.

### G10. No localized customer-facing copy on Departure

`operational_notes` is internal-only. All customer-visible copy (titles, descriptions, highlights, marketing text) lives on `tour_products`, not `departures`. The frontend renders departure-specific data (date, price, seats remaining) but never localized prose from a Departure row. This protects against the first frontend dev who tries to add a `description_ru` column.

---

## 11. Open questions for review (originally Section 10)

The following non-blocking questions remain. Decide as needs arise — these are NOT blockers for Phase 1.

1. **Multi-day departures:** Single row with start date + duration from TourProduct (recommended) vs row-per-day. **Recommend:** single row. Per G9, multi-day with per-day operations is a future Phase X spec, not a column addition.
2. **Multiple pickup points per departure:** Defer to Phase 8 with templates. Phase 1 is single pickup_point per departure.
3. **`booking_inquiries.travel_date` sync:** Copy from departure at save time, then read-only display. Confirmed for Phase 2.
4. **Departure deletion policy:** Soft delete enabled. Hard delete reserved for test data only. Cancel-don't-delete for production departures.
5. **Supplier dispatch contract** (flagged by architect review 2026-04-28): Today suppliers are FK columns (`driver_id`, `guide_id`, `vehicle_id`). The actual dispatch *event* — confirming the driver has accepted, sending them the manifest, getting their reply — is unmodeled. Existing `driver_dispatched_at` / `guide_dispatched_at` timestamps on `booking_inquiries` are inquiry-level, not departure-level. Phase 3 (operator economics) or Phase 5 (supplier portal) will need to address this. Likely answer: a `departure_supplier_dispatches` audit table with `accepted_at`, `manifest_sent_at`, `reply_received_at` per assignment. Out of scope for Phase 1.
6. **`booking_inquiries` column-count tripwire:** When the table reaches 75 columns OR a 6th distinct identity wants to bolt on, the next feature is a domain extraction conversation, not a column. Add to PRINCIPLES.md when it lands.
7. **Existing `inquiry:send-payment-reminders` cron** must be audited before Phase 1 to confirm its trigger field. If it currently triggers on a field other than `payment_due_at`, decide whether the new column replaces or augments the existing trigger to avoid double-fires.

---

## 12. Test Infrastructure (permanent platform documentation)

### 12.1 Purpose

Tests for any DB-touching feature must run against an **isolated MySQL database** — never the production `jahongir` database, never SQLite as a parity substitute. The test database `jahongirnewapp_test` exists permanently on the Jahongir VPS so that:

- `lockForUpdate()` semantics match production (SQLite is single-writer; locks are no-ops)
- `whereRaw` subqueries (e.g. `Departure::scopeBookableWithSeats`) are validated against MySQL syntax
- Foreign key constraints behave identically to production
- Index-related migration rollback issues surface in test, not in deploy
- Concurrency tests prove the platform's seat-mutation contract

This is platform infrastructure, not yurt-departures-specific. Future features touching the database must use it.

### 12.2 Provisioning (one-time, requires privileged DB user)

Run by a privileged DB user (root via `sudo mysql`, or the credentials in `/etc/mysql/debian.cnf`):

```sql
CREATE DATABASE IF NOT EXISTS jahongirnewapp_test
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON jahongirnewapp_test.* TO 'jahongirapp'@'localhost';
FLUSH PRIVILEGES;
```

**Charset/collation must be `utf8mb4` / `utf8mb4_unicode_ci`** to match production.
Grant scope is **strictly `jahongirnewapp_test.*`** — never global. Principle of least privilege.

### 12.3 Verification protocol (run before EVERY test session)

Three independent confirmations that the test runner targets the isolated DB:

```bash
# A. Static — phpunit.xml override
grep DB_DATABASE phpunit.xml
# → <env name="DB_DATABASE" value="jahongirnewapp_test"/>

# B. Static — .env.testing
grep DB_DATABASE .env.testing
# → DB_DATABASE=jahongirnewapp_test

# C. Runtime — actual connection
php artisan tinker --env=testing \
  --execute="echo DB::connection()->getDatabaseName();"
# → jahongirnewapp_test
```

**Plus** charset verification (existence ≠ correct charset):

```sql
SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME
FROM information_schema.SCHEMATA
WHERE SCHEMA_NAME = 'jahongirnewapp_test';
```

If any output shows `jahongir` (production) or `latin1` / non-utf8mb4 charset, **abort immediately**.

Before the verification above, clear stale config caches (Laravel cache mistakes are real):

```bash
php artisan config:clear --env=testing
php artisan cache:clear  --env=testing
```

### 12.4 Safety rules

- ❌ Never run `migrate`, `migrate:fresh`, `migrate:rollback`, `db:seed`, or `db:wipe` against the `jahongir` production database
- ❌ Never grant the test user privileges beyond `jahongirnewapp_test.*`
- ❌ Never substitute SQLite for MySQL "to go faster" — it hides the exact bugs the test infrastructure exists to catch
- ❌ Never reuse a tenant DB or staging DB for tests — the test DB must be wiped freely (`migrate:fresh`) without consequences
- ❌ Never commit a real `.env.testing` to git — only `.env.testing.example` (no secrets)
- ✅ Always use `--env=testing` explicitly on artisan commands during test sessions
- ✅ Always verify connection target before running migrations
- ✅ Always include rollback rehearsal (`fresh → rollback → migrate`) in foundation verification

### 12.5 Artifact standard

Every Phase 1+ feature that touches the database must produce a verification artifact at:

```
docs/architecture/<feature>/PHASE_<N>_FOUNDATION_VERIFICATION.md
```

Artifact sections:
- **A. Environment** — branch, commit SHA (`git rev-parse HEAD`), DB target, clone path, MySQL version, date
- **B. Migrations** — full `migrate` log, table list after migration
- **C. Test results** — test command + output
- **D. Concurrency trust test** — extracted, highlighted (when relevant)
- **E. Rollback validation** — `migrate:fresh → migrate:rollback → migrate` cycle log
- **F. Pass / Fail** — green-light or red-light with cause

The artifact is committed to the feature branch alongside the code. It is NOT optional.

### 12.6 Future branch rule

Any feature branch that adds, alters, or queries database schema MUST validate on `jahongirnewapp_test` before merging. Tests passing locally on SQLite are insufficient. Tests passing in CI alone are insufficient if CI uses anything other than MySQL parity. The Jahongir VPS test infrastructure is the single source of truth for "schema is safe to deploy."

### 12.7 Known failure modes (and their causes)

| Symptom | Likely cause | Fix |
|---|---|---|
| `Access denied for user 'forge'@'localhost'` | Local Laravel default user, no MySQL grants | Run on Jahongir VPS test DB instead |
| `Connection refused` to MySQL | MySQL service not running, or wrong port | `systemctl status mysql` |
| Migration runs against `jahongir` not `jahongirnewapp_test` | Stale config cache | `config:clear --env=testing` before running |
| Tests fail with FK errors that don't reproduce locally | Test DB has stale rows from an aborted prior run | `migrate:fresh --env=testing` |
| Rollback succeeds locally but fails on test DB | MySQL auto-drops FK-backed indexes; Postgres doesn't | Use `Schema::hasIndex()` guard in `down()` |
| `lockForUpdate()` "tests pass" but production overbooks | SQLite was used; locks were no-ops | Always test on MySQL test DB |
| `whereRaw` subquery returns wrong result | MySQL vs Postgres syntax difference | Validate on MySQL test DB |
| `migrate:fresh` hangs | Long-running transaction holding row locks | Kill stale connections; ensure no open psql/mysql sessions |
| Test DB has tables before tests start | Previous test run aborted mid-flight | `migrate:fresh --env=testing` |
| Grant "works" but ALTER fails | Grant is global `USAGE` only, not on the test DB | Re-run GRANT with explicit `jahongirnewapp_test.*` scope |
| `Class "Database\Factories\XYZFactory" not found` during test run | Upstream model declares `HasFactory` but factory file was never shipped (shared-domain test debt) | Create the missing factory under `database/factories/`. Treat as platform infrastructure patch (separate commit, e.g. `chore(testing): add XYZFactory`), then re-run targeted test suite from a clean state. Do NOT bypass `HasFactory` by hand-rolling fixtures in the test class — it hides the same gap for the next feature. |

### 12.7.1 Proactive shared-domain factory audit

This pattern recurred 3× during Phase 1 Foundation Verification (Rounds 1–3, plus Commit 2.0). It is endemic to the codebase. Before any major feature test phase begins, run:

```bash
for model in $(grep -lE "use HasFactory" app/Models/*.php); do
  modelname=$(basename "$model" .php)
  if [ ! -f "database/factories/${modelname}Factory.php" ]; then
    echo "MISSING: ${modelname}Factory"
  fi
done
```

If your feature touches any model in the MISSING list, create that factory FIRST as a separate `chore(testing)` commit. Discovering it mid-test-run is wasted cycles.

As of 2026-04-28: the audit reveals 30+ HasFactory models without factories (including BookingFactory, AccommodationFactory, CarFactory, TourPriceTierFactory, etc.). They are not blocking Phase 1 work — but the next feature touching any of them will trigger the same mid-run discovery unless audited up front.

### 12.8 Sign-off after provisioning

Before marking test infrastructure operational, verify:

- [ ] `jahongirnewapp_test` database exists with `utf8mb4` / `utf8mb4_unicode_ci`
- [ ] `jahongirapp@localhost` has `ALL PRIVILEGES` on `jahongirnewapp_test.*` only
- [ ] `.env.testing.example` committed; `.env.testing` in `.gitignore`
- [ ] Triple verification (§12.3 A/B/C) all return `jahongirnewapp_test`
- [ ] Charset verification returns `utf8mb4`
- [ ] `php artisan migrate --env=testing` succeeds
- [ ] `php artisan migrate:rollback --env=testing` succeeds
- [ ] `php artisan migrate:fresh --env=testing` succeeds
- [ ] First feature branch foundation artifact published

---

## 13. Sign-off

- [x] Architecture rules drafted
- [x] State machine defined
- [x] Group vs private semantics defined
- [x] Reservation transaction designed (with Q4 hold cap + correct lockForUpdate pattern)
- [x] Seat Mutation Matrix locked (§5.0)
- [x] Q1–Q7 decisions locked (2026-04-28)
- [x] Governance rules G1–G10 locked (G8 no-seat-math-in-presentation, G9 schema limits, G10 no-localized-copy added 2026-04-28)
- [x] Independent code-reviewer pass complete (REQUEST CHANGES → applied)
- [x] Independent software-architect pass complete (STRONG → applied)
- [ ] Delta re-review by code-reviewer
- [ ] Operator walkthrough scheduled

**Next step:** Delta re-review on patches, then Phase 1 coding begins.
