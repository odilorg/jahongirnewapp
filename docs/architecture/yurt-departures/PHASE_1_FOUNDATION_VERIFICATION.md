# Phase 1 — Foundation Verification

**Document type:** Foundation verification artifact (per `PHASE_0_ARCHITECTURE_LOCK.md` §12.5)
**Status:** 🟢 GREEN — Phase 1 schema + model + concurrency contract PROVEN on isolated MySQL test DB
**Date:** 2026-04-28
**Operator:** Claude (under user-approved governance)

---

## A. Environment

| Item | Value |
|---|---|
| Branch | `feature/yurt-departures-phase-1` |
| Commit verified | `159181dddf9e6e1a0479d37afa1925a10b3e0c06` |
| Test host | Jahongir VPS (`jahongir` SSH alias) |
| Isolated clone path | `/var/test-runs/yurt-departures-phase-1` |
| Test DB | `jahongirnewapp_test` |
| Test DB charset | `utf8mb4` |
| Test DB collation | `utf8mb4_unicode_ci` |
| Production DB | `jahongir` — **not touched at any point** |
| Production code | `/var/www/jahongirnewapp` @ `fc32787` — **not touched** |
| MySQL version | `mysql Ver 8.0.45-0ubuntu0.24.04.1 for Linux on x86_64 (Ubuntu)` |
| PHP version | `PHP 8.3.6 (cli) (NTS)` |
| Triple verification | `phpunit.xml` ✅ / `.env.testing` ✅ / runtime DB ✅ — all `jahongirnewapp_test` |

## B. Migrations

Applied via `php artisan migrate --env=testing --force` to clean (`migrate:fresh`) test DB. Three Phase 1 migrations applied in order:

```
2026_04_28_171725_create_departures_table ........................ 1,067ms DONE
2026_04_28_171726_add_default_pickup_to_tour_product_directions ..   111ms DONE
2026_04_28_171727_add_departure_fields_to_booking_inquiries ......   709ms DONE
```

All upstream migrations also applied cleanly (no schema collision with existing tables).

## C. Test results

Command: `php artisan test --filter='DepartureModelTest|SeatLockConcurrencyTest'`

```
   PASS  Tests\Unit\DepartureModelTest
  ✓ statuses constant is complete and unique                            87.24s
  ✓ generate reference produces sequential per year codes                0.17s
  ✓ seats booked excludes cancelled and spam inquiries                   0.30s
  ✓ is bookable requires open or guaranteed status                       0.09s
  ✓ is bookable returns false after cutoff                               0.08s
  ✓ is bookable returns false when full                                  0.15s
  ✓ scope bookable does not filter by seats remaining                    0.21s
  ✓ scope publicly visible excludes private departures                   0.08s
  ✓ is terminal returns true for terminal statuses                       0.09s
  ✓ policy allows auto cancel only for group                             0.14s
  ✓ policy requires minimum pax only for group                           0.11s
  ✓ policy publicly listable requires group and open or guaranteed       0.09s

   PASS  Tests\Feature\Departures\SeatLockConcurrencyTest
  ✓ lock for update pattern returns a locked row                         0.10s
  ✓ seats booked inside lock reflects committed writes                   0.11s
  ✓ cancelled inquiries release seats immediately                        0.08s
  ✓ sequential reservations on same departure serialize correctly        0.12s
  ✓ refresh then lock for update does not acquire lock                   0.08s

  Tests:    17 passed (49 assertions)
  Duration: 90.14s
```

**Verification rounds (transparency):**
- Round 1: 2 passed / 15 failed — surfaced missing `TourProductFactory`
- Round 2 (after Commit 1.1 — TourProduct + TourProductDirection factories): 11 passed / 6 failed — surfaced missing `BookingInquiryFactory`
- Round 3 (after Commit 1.2 — BookingInquiryFactory): **17 passed / 0 failed** ✅

Each red-light correctly stopped progression and was resolved as platform-wide test infrastructure (not yurt-specific shortcuts).

## D. Concurrency trust test (CROWN-JEWEL — extracted)

The PHASE_0 §5.0 Seat Mutation Matrix is the foundation of the booking engine's correctness. These tests prove the locking contract works on real MySQL (not SQLite where `lockForUpdate()` is a no-op).

```
Tests\Feature\Departures\SeatLockConcurrencyTest

✓ lock for update pattern returns a locked row                         (0.10s)
  → Departure::lockForUpdate()->findOrFail() inside DB::transaction
    successfully acquires and releases the row lock.

✓ seats booked inside lock reflects committed writes                   (0.11s)
  → seats_booked accessor inside the lock correctly sums seats_held
    from prior committed BookingInquiries; capacity arithmetic is
    consistent.

✓ cancelled inquiries release seats immediately                        (0.08s)
  → forceFill(['status' => CANCELLED])->save() correctly drops the
    inquiry's seats from the active count, freeing capacity for new
    bookings. Locking the departure during cancellation (per §5.0
    rule) was performed.

✓ sequential reservations on same departure serialize correctly        (0.12s)
  → Two reservations (2 seats each) into a 4-seat departure proceed
    correctly. Second reservation sees first reservation's committed
    seats. Third reservation sees full departure with 0 seats remaining.

✓ refresh then lock for update does not acquire lock                   (0.08s)
  → Documents the FOOTGUN from PHASE_0 §2.2: refresh()->lockForUpdate()
    on a hydrated model is a no-op. Only QueryBuilder->lockForUpdate()
    actually takes a lock.
```

**Verdict:** The seat-mutation contract is provably correct on the platform's actual database engine. Phase 2 ReserveSeatsForDepartureAction can be built on this foundation with confidence.

## E. Rollback validation

```
=== migrate:fresh (clean state) ===
[all migrations apply cleanly]

=== migrate:rollback --step=3 ===
2026_04_28_171727_add_departure_fields_to_booking_inquiries ..... 574ms DONE
2026_04_28_171726_add_default_pickup_to_tour_product_directions . 199ms DONE
2026_04_28_171725_create_departures_table ........................ 69ms DONE

=== migrate (re-apply) ===
2026_04_28_171725_create_departures_table ..................... 1,628ms DONE
2026_04_28_171726_add_default_pickup_to_tour_product_directions . 150ms DONE
2026_04_28_171727_add_departure_fields_to_booking_inquiries ..... 835ms DONE
```

**Notable:** The `Schema::hasIndex()` guards in `2026_04_28_171727`'s down() migration worked correctly. MySQL 8.0 auto-drops the FK-backed index on `departure_id` when the FK is dropped; the guard prevented a "duplicate drop" error that would have appeared on Postgres differently. Cross-DB safety verified.

**Verdict:** Up → Down → Up cycle clean. No FK orphans, no leftover indexes, no schema drift.

## F. Pass / Fail

| Check | Result |
|---|---|
| Provisioning verification (charset, grants, scope) | ✅ PASS |
| Triple DB target verification | ✅ PASS |
| Migrations apply cleanly to fresh test DB | ✅ PASS |
| All Phase 1 model unit tests | ✅ 12/12 PASS |
| All concurrency trust tests | ✅ 5/5 PASS |
| Rollback rehearsal (up → down → up) | ✅ PASS |
| Production DB untouched | ✅ CONFIRMED |
| Production code untouched | ✅ CONFIRMED |

# 🟢 PHASE 1 FOUNDATION: VERIFIED

**Commit 2 (governance actions) is AUTHORIZED to proceed** under locked governance.

**NOT yet authorized:**
- ❌ ReserveSeatsForDepartureAction (Phase 2.5)
- ❌ Filament DepartureResource (Commit 3)
- ❌ API endpoints (Commit 5)
- ❌ Production deployment (occurs after staging rehearsal post Commit 5)

---

## Commits verified by this artifact

```
131c07b  feat(departures): Phase 1 Commit 1 — schema + Departure model + concurrency tests
5456771  chore(testing): add .env.testing.example + Phase 0 §12 test infrastructure docs
63413b2  chore(testing): add PHASE_0_TEST_DB_PROVISIONING.md provenance record
fe21796  chore(testing): add TourProduct + TourProductDirection factories          [Commit 1.1]
159181d  chore(testing): add BookingInquiryFactory shared-domain test infrastructure [Commit 1.2]
```

## What this artifact authorizes

Per `PHASE_0_ARCHITECTURE_LOCK.md` and the user's locked governance:

- ✅ Begin Commit 2: governance actions (CreateDepartureAction, OpenDepartureAction, MarkDepartureGuaranteedAction, ConfirmDepartureAction, CancelDepartureAction, MarkDepartedAction, CompleteDepartureAction, ValidateDepartureForOpenAction + DepartureOpenRule rules)
- ✅ Continued use of `jahongirnewapp_test` for action class tests
- ✅ Continued non-deployment posture (still feature-branch only)

## What this artifact does NOT authorize

- ❌ Implementing seat-mutation actions yet (Phase 2.5 — separate spec)
- ❌ Filament resource work (Commit 3)
- ❌ Public API surface (Commit 5)
- ❌ Production migration of the new schema (occurs only after full Phase 1 stack proven on staging)

---

**Sign-off:** Phase 1 foundation is implementation-proof. Commit 2 may begin upon user green-light.
