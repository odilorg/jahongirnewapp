# L-002 · BotPaymentService side-by-side diff

**Phase:** ticket-level investigation · **Generated:** 2026-04-18 · **Commit:** `dab5add`
**Read-only.** No code changes. Findings fed to user before rewrite.

---

## 0. TL;DR

| Claim | Evidence |
|---|---|
| `App\Services\Fx\BotPaymentService` is **dead in production** | zero `use App\Services\Fx\BotPaymentService` imports in `app/`. Only consumer: 1 test file. |
| `App\Services\BotPaymentService` (top-level) is **the live service** | imported by `CashierBotController:21`, 4 test files, and **has group-booking support** that the Fx/ variant lacks. |
| The docblock on top-level is **misleading** | it marks itself `@deprecated Superseded by Fx/…` but the opposite is true — top-level has the newer feature set. |
| **Feature flag `FX_BOT_PAYMENT_V2`** is **ON** in production, but it does NOT switch between the two services | its single consumer is `Beds24WebhookController:480` which uses it to suppress Beds24-side `CashTransaction` ingestion (not to pick a service class). |
| **Tour runtime impact: zero** | no tour callers, no tour tables written, no shared enum modification needed. |
| **Canonical choice: top-level** (per Phase 4 decision, now verified by code) | richer feature set + only production-wired variant. Fx/ gets deleted. |

---

## 1. Class comparison

| Axis | `App\Services\BotPaymentService` (top-level) | `App\Services\Fx\BotPaymentService` |
|---|---|---|
| File size | 367 LOC | 244 LOC |
| Namespace | `App\Services` | `App\Services\Fx` |
| Self-label | `@deprecated` (but wrong) | implicitly canonical (but unused) |
| Production callers | `CashierBotController` | **none** |
| Test coverage | 4 test files | 1 test file |
| Group-booking support | ✅ yes (`GroupAwareCashierAmountResolver`, group duplicate guard, group columns) | ❌ no |
| Soft guard "already fully paid" | ✅ `warnIfAlreadyFullyPaid` | ❌ absent |
| Override approval flow | ✅ via `FxManagerApprovalService` | ✅ via `FxManagerApprovalService` |
| Lock strategy | `Beds24Booking::lockForUpdate()` | `BookingFxSync::lockForUpdate()` |

## 2. Public API

| Method | Top-level signature | Fx/ signature |
|---|---|---|
| `preparePayment` | `(string $beds24BookingId, string $botSessionId): PaymentPresentation` | `(string $beds24BookingId, float $usdAmount, Carbon $arrivalDate, string $guestName, string $roomNumber): PreparedPayment` |
| `recordPayment` | `(RecordPaymentData $data): CashTransaction` | `(RecordPaymentData $data): CashTransaction` — different DTO (`DTOs\Fx\RecordPaymentData`) |

**Divergent surface:** the two classes are not drop-in compatible — even the prepare step has different inputs. Callers are built around the top-level signature.

## 3. Constructor dependencies

| Dep | Top-level | Fx/ |
|---|---|---|
| `FxSyncService` | ✅ | ✅ |
| `FxOverridePolicyEvaluator` (canonical, in `Fx/`) | ✅ via alias `FxOverridePolicyEvaluator` | ✅ direct |
| `FxManagerApprovalService` | ✅ (plain, imported via `App\Services\FxManagerApprovalService` — **note: there is also a duplicate of FxManagerApprovalService in both `Services/` and `Services/Fx/`; see DOMAINS.md §A12**) | ✅ (uses the `Fx/` variant) |
| `Beds24PaymentSyncService` | ✅ (from `Fx/`) | ✅ (from `Fx/`) |
| `GroupAwareCashierAmountResolver` | ✅ | ❌ |
| `SettlementCalculator` | ❌ | ✅ |

Top-level has 5 deps; Fx/ has 5 deps (different mix). `GroupAwareCashierAmountResolver` and `SettlementCalculator` are mutually exclusive.

## 4. DTOs used

| DTO | Where |
|---|---|
| `App\DTO\PaymentPresentation` (164 LOC, rich, with group fields + `fromSync()` + `fromArray()` + TTL) | top-level + controller |
| `App\DTO\RecordPaymentData` (25 LOC) | top-level |
| `App\DTO\GroupAmountResolution` (53 LOC) | top-level |
| `App\DTOs\Fx\PaymentPresentation` (separate class, namespace collision risk) | Fx/ + Fx test only |
| `App\DTOs\Fx\PreparedPayment` (15 LOC) | Fx/ + Fx test only |
| `App\DTOs\Fx\RecordPaymentData` (29 LOC) | Fx/ + Fx test only |
| `App\DTOs\Fx\RemainingBalance` | Fx/ via `SettlementCalculator::remaining()` |

## 5. Exceptions thrown

| Exception | Top-level | Fx/ |
|---|---|---|
| `App\Exceptions\BookingNotPayableException` | ✅ | ❌ |
| `App\Exceptions\DuplicateGroupPaymentException` | ✅ | ❌ |
| `App\Exceptions\DuplicatePaymentException` | ✅ | ✅ |
| `App\Exceptions\IncompleteGroupSyncException` | ✅ (via resolver) | ❌ |
| `App\Exceptions\ManagerApprovalRequiredException` | ✅ | ❌ (Fx/ throws `PaymentBlockedException` instead) |
| `App\Exceptions\PaymentBlockedException` | ✅ | ❌ |
| `App\Exceptions\Fx\PaymentBlockedException` | ❌ | ✅ |
| `App\Exceptions\Fx\StalePaymentSessionException` | ❌ | ✅ |
| `App\Exceptions\StalePaymentSessionException` | ✅ | ❌ |

**Two parallel exception classes each for "payment blocked" and "stale session".** Another duplication — callers must handle both if they ever exercise both paths. Since no caller exercises Fx/, the `App\Exceptions\Fx\*` variants are effectively dead.

## 6. Callers — ACTUAL (verified)

### Top-level callers (live)

| File | Context |
|---|---|
| `app/Http/Controllers/CashierBotController.php:21, 37, 52, 553, 888, 898` | production — injects service, calls `preparePayment`, calls `recordPayment` |
| `tests/Unit/BotPaymentServiceOverrideTest.php` | unit test — override evaluator DI |
| `tests/Feature/CashierBot/GroupPaymentIntegrationTest.php` | integration test — full group-booking path |
| `tests/Feature/CashierBot/OnDemandBookingImportTest.php` | feature test — on-demand import |
| `tests/Feature/LegacyPaymentFallbackBlockTest.php` | feature test — asserts legacy fallback is blocked |

### Fx/ callers (dead)

| File | Context |
|---|---|
| `tests/Feature/Fx/BotPaymentDuplicateGuardTest.php` | **only consumer — a test file** |

**No production caller imports `App\Services\Fx\BotPaymentService`.**

## 7. Feature flag `FX_BOT_PAYMENT_V2` (reality check)

Production state (verified on VPS `/var/www/jahongirnewapp/.env`): `FX_BOT_PAYMENT_V2=true`.

But the flag is consulted in **one** place: `app/Http/Controllers/Beds24WebhookController.php:480`:

```php
case 'payment_updated':
    // When FX_BOT_PAYMENT_V2 is active the bot is the single source of truth
    // for cash transactions — Beds24 webhooks must not create drawer records.
    if (!config('features.fx_bot_payment_v2', false)) {
        $this->handlePaymentSync($booking, $change, $oldData, $raw);
    }
    break;
```

It **suppresses the Beds24 webhook from writing its own `CashTransaction`** when the bot is the single source — unrelated to choosing between the two `BotPaymentService` classes. The Fx/ service's docblock claim that "`FX_BOT_PAYMENT_V2` must be enabled for this service to be used" is false — no DI wiring or factory reads it.

## 8. Data writes compared

Both write to `cash_transactions` and indirectly to `beds24_payment_syncs` via `Beds24PaymentSyncService::createPending`. Both dispatch `Beds24PaymentSyncJob` (flag-gated).

### Columns on `cash_transactions::create`

| Column | Top-level writes | Fx/ writes |
|---|:---:|:---:|
| `cashier_shift_id` | ✅ | ✅ |
| `type` (`'in'`) | ✅ string | ✅ enum value |
| `amount` | ✅ | ✅ |
| `currency` | ✅ | ✅ enum value |
| `category` (`'sale'`) | ✅ string | ✅ enum value |
| `beds24_booking_id` | ✅ | ✅ |
| `payment_method` | ✅ | ✅ |
| `guest_name` | ✅ | ✅ |
| `room_number` | ❌ | ✅ |
| `created_by` | ✅ | ✅ |
| `occurred_at` | ✅ | ✅ |
| `reference` | ✅ (`"Beds24 #{$id}"`) | ❌ |
| `notes` | ✅ (multiline, richly formatted) | ❌ |
| `source_trigger` | ✅ (literal `'cashier_bot'`) | ✅ (enum value, same string) |
| `booking_fx_sync_id` | ✅ | ✅ |
| `daily_exchange_rate_id` | ✅ | ❌ |
| `exchange_rate_id` | ❌ | ✅ |
| `amount_presented_uzs`/`eur`/`rub` | ✅ | ✅ |
| `amount_presented_usd` | ❌ | ✅ |
| `presented_currency` | ✅ | ✅ |
| `amount_presented_selected` | ✅ | ✅ |
| `usd_equivalent_paid` | ✅ | ✅ |
| `is_override` | ✅ | ✅ |
| `within_tolerance` | ❌ | ✅ |
| `variance_pct` | ❌ | ✅ |
| `override_tier` | ✅ | ✅ |
| `override_reason` | ✅ | ✅ |
| `override_approved_by` | ✅ | ❌ |
| `override_approved_at` | ✅ (in create) | ✅ (separate update after consume) |
| `override_approval_id` | ❌ (in create) | ✅ |
| `presented_at` | ✅ | ✅ |
| `recorded_at` | ✅ | ✅ |
| `bot_session_id` | ✅ | ✅ |
| `group_master_booking_id` | ✅ | ❌ |
| `is_group_payment` | ✅ | ❌ |
| `group_size_expected` | ✅ | ❌ |
| `group_size_local` | ✅ | ❌ |

**Divergence of columns each writes: 9 top-level-only, 6 Fx/-only.** All DB columns exist (verified via `php artisan db:table cash_transactions`).

**Both FK alternatives (`daily_exchange_rate_id` vs `exchange_rate_id`) exist on the table** — pointing to two different rate stores (`daily_exchange_rates` vs `exchange_rates`). Top-level chose daily; Fx/ chose the other. Merge decision needed.

## 9. Tour-runtime safety — explicit proof (the user's binding requirement)

### 9.1 Tables written by either class

| Table | Also used by tour? | Write direction |
|---|---|---|
| `cash_transactions` | ❌ only hotel runtime writes (verified in SCOPE_GATE §1.1 — zero tour writers) | Both classes write |
| `beds24_payment_syncs` | ❌ hotel-only (tied to Beds24 PMS = hotel) | Both via `createPending` |
| `fx_manager_approvals` | ❌ hotel-only (cashier-shift approval) | Both via `approvalService->consume` |

**No tour-finance table is touched by either class.**

### 9.2 Shared enums / models — any indirect tour impact?

| Shared dependency | Who else uses it? | Impact if we edit? |
|---|---|---|
| `App\Enums\Currency` | broad — including tour code | **we will not modify it**, only consume values |
| `App\Enums\OverrideTier` | FX override chain only (hotel) | no tour touch |
| `App\Enums\CashTransactionSource` | money code only | no tour touch |
| `App\Enums\TransactionType`, `TransactionCategory` | cashier + ledger only | no tour touch |
| `App\Models\CashTransaction` | cashier-bot only (0 tour writers) | no tour touch |
| `App\Models\Beds24Booking` | hotel PMS only | no tour touch |
| `App\Models\BookingFxSync` | hotel FX only | no tour touch |
| `App\Models\Beds24PaymentSync` | hotel PMS only | no tour touch |
| `App\Services\Fx\FxSyncService` | hotel FX only | no tour touch |
| `App\Services\Fx\OverridePolicyEvaluator` | hotel only | no tour touch |
| `App\Services\Fx\Beds24PaymentSyncService` | hotel only | no tour touch |

**No shared enum, model, or service needs to change.** The refactor reshuffles a hotel-only service and deletes its dead Fx/ twin. Tour runtime is untouched by construction.

### 9.3 Scope gate header for L-002

```
Domain:          hotel-only
Runtime impact:  hotel-only
Tour risk:       none
Proof of safety: (a) hotel-only in runtime — zero tour callers,
                  zero tour tables, zero shared-enum modification.
                  Verified via grep on 2026-04-18.
```

## 10. Rewrite plan (for your approval — no code yet)

### 10.1 Canonical choice: top-level (confirmed)

- Keep `App\Services\BotPaymentService` (the live one with group support).
- **Remove** the misleading `@deprecated` docblock on it.
- **Delete** `app/Services/Fx/BotPaymentService.php`.

### 10.2 Columns to port from Fx/ into top-level

Port these into the `CashTransaction::create([...])` payload inside the top-level class so the canonical service writes the **superset** of audit columns:

| Port | Source | Notes |
|---|---|---|
| `room_number` | from `$p` (add to `PaymentPresentation`) | currently absent in top-level DTO |
| `amount_presented_usd` | from `$p` (add to `PaymentPresentation`) | currently absent |
| `within_tolerance` | from `$evaluation->withinTolerance` | already in scope |
| `variance_pct` | from `$evaluation->variancePct` | already in scope |
| `override_approval_id` | FK to `fx_manager_approvals.id` | already held on `$data->managerApproval?->id`, just add to create |
| `exchange_rate_id` | `$p->exchangeRateId` (add to DTO)? | 🟡 **decision**: two FKs to two rate tables exist; do we want both? See §10.4 |

### 10.3 DTOs to update

- `App\DTO\PaymentPresentation`: add `?int $exchangeRateId`, `?string $roomNumber`, `int $usdPresented` (the last one probably just uses the sync's `usd_final`).
- `App\DTO\RecordPaymentData`: no shape change expected; already carries `managerApproval` object.

### 10.4 Open merge decisions (bring to user)

1. **Keep both rate FKs (`daily_exchange_rate_id` + `exchange_rate_id`) on `cash_transactions`?** Both columns exist in DB, different stores. Simplest: populate both for full traceability. Alternative: pick one, leave the other NULL.
2. **Standardize enum use in writes**: top-level uses string literals (`'in'`, `'sale'`, `'cashier_bot'`), Fx/ uses `TransactionType::IN->value` style. Functionally identical. **Convert top-level to enum values** for typo safety? (recommended — aligns with our future `SourceTrigger` plan.)
3. **Exception class cleanup**: `App\Exceptions\Fx\PaymentBlockedException` and `App\Exceptions\Fx\StalePaymentSessionException` become unused after deletion. Delete them? Keep as alias? (recommended: delete; no caller.)
4. **Dead DTOs after deletion of Fx/ BotPaymentService**:
   - `App\DTOs\Fx\PaymentPresentation` — used only by Fx/ BotPaymentService + its test. Delete.
   - `App\DTOs\Fx\PreparedPayment` — same. Delete.
   - `App\DTOs\Fx\RecordPaymentData` — same. Delete.
   - `App\DTOs\Fx\RemainingBalance` — returned by `SettlementCalculator::remaining()`. **`SettlementCalculator` is still used only by Fx/ BotPaymentService** (verified). Delete both after Fx/ BotPaymentService is removed, OR keep the calculator as a public utility. Recommended: delete for now, resurrect if a future caller emerges.
5. **One test to handle**: `tests/Feature/Fx/BotPaymentDuplicateGuardTest.php`. Two options:
   - (a) rewrite it to test the top-level class (minor porting — replace DTO imports, adjust constructor). Preserves extra test coverage.
   - (b) delete it — its "duplicate guard" coverage is subsumed by `tests/Feature/CashierBot/GroupPaymentIntegrationTest.php` which exercises the same guard through the top-level class.
   - **Recommendation: (b) delete.** The scenarios are already covered.

### 10.5 Caller rewiring

- `CashierBotController` — no change. Already uses top-level.
- All 4 top-level tests — no change.
- The 1 Fx test — delete or port (§10.4 decision 5).

### 10.6 Final steps

1. Port the 5 columns and extend DTO (§10.2, §10.3)
2. Run existing regression tests — `tests/Unit/BotPaymentServiceOverrideTest.php` + `tests/Feature/CashierBot/*`
3. Handle the Fx/ test per §10.4 decision 5
4. Delete `app/Services/Fx/BotPaymentService.php`
5. Delete dead Fx DTOs + exceptions per §10.4 decisions 3, 4
6. Remove/fix the misleading `@deprecated` docblock on the canonical class
7. Grep to confirm zero remaining references to the deleted symbols
8. Commit + push; no production deploy needed for L-002 alone (pairs with L-003+)

## 11. Success criteria (your exact words, re-stated)

| Criterion | How it is proven |
|---|---|
| One canonical service | Only `App\Services\BotPaymentService` remains after the PR |
| Zero behavior loss | Superset column write; all tests still pass; group-booking path unchanged |
| Zero tour callers | `grep App\\Services(\\Fx)?\\BotPaymentService app/` returns only `CashierBotController` (already the case; stays) |
| Zero shared-runtime regression risk | No shared enum/model/service modified; only hotel-scoped removals |

---

## 12. Open questions — bring to user before code changes

1. **Canonical confirmation** — §10.1: keep top-level, delete Fx/. Any objection? (Answer likely: proceed.)
2. **Rate FK policy** — §10.4 (1): populate both `daily_exchange_rate_id` and `exchange_rate_id`, or just one?
3. **Enum-in-writes convention** — §10.4 (2): standardize on `TransactionType::IN->value` style in the create call?
4. **Dead-class deletions** — §10.4 (3, 4): OK to delete the Fx/ exception classes, DTOs, and `SettlementCalculator`?
5. **Fx test fate** — §10.4 (5): delete the only Fx test, or rewrite it against top-level?

Once these are answered, the rewrite becomes mechanical (half-day work). Before any code change I'll produce a second short doc showing exactly what files change and in what order.

---

**Status:** Investigation complete. No code has been written. Awaiting answers to the 5 open questions in §12.
