# REFACTOR PLAN — Jahongir Hotel Operations System

**Phase:** 5 · **Generated:** 2026-04-18 · **Commit baseline:** `2af7fd3`
**Scope:** turn Phase 4's L-001 … L-019 sketch into executable tickets. Financial core refactor.
**Deferred:** Task core (Phase 4.5) — separate plan once its deep-dive is complete.

---

## 0. How to read this document

Every ticket follows the same structure:

```
L-NNN · <Title>             [P0 | P1 | P2]    Risk: low / medium / high
Scope:      files touched
Why:        linked finding from Phase 2 / 3.5 / 4
Steps:      ordered, atomic
Depends on: predecessor ticket IDs
Test plan:  explicit acceptance criteria
Rollback:   how to undo if production regresses
Effort:     S (≤1d) / M (2–3d) / L (4–7d) / XL (>1w)
```

**Priority meaning:**
- **P0** — blocker. Nothing else proceeds until done. Data-integrity or ambiguous-wiring risk.
- **P1** — core refactor work. Directly builds the ledger and migrates flows.
- **P2** — cleanup. Runs in parallel with P1 where it doesn't cross domains.

**Risk meaning:**
- **low** — additive, reversible, no production user impact
- **medium** — touches production code path, behind feature flag, rollback via flag
- **high** — production user impact even with flag, requires window + signoff + backup

---

## 1. The 8 locked decisions + firewall (restated so this doc is self-contained)

1. One `BotPaymentService` in `app/Services/` — `Fx/` copy deleted
2. One `ReconciliationService` — absorb `Fx/WebhookReconciliationService`
3. Resolve 3 duplicate `Schema::create` migrations — P0 blocker
4. `SourceTrigger` unified enum replaces all string literals
5. `PaymentMethod` stored as enum/code; display belongs to UI
6. `GuestPayment.amount` = actual paid amount (never `price_quoted`)
7. Retire `cash_transactions` → migrate into `ledger_entries`; keep as read-only facade during coexistence
8. Filament forbidden from creating money rows directly
9. **Write firewall** — runtime guard blocks direct `LedgerEntry::create` outside approved context
10. **Absolute rule** — no model represents money unless it maps to the ledger

---

## 2. Execution order (dependency DAG, condensed)

```
                                  ┌──────── L-021..L-029 (P2 cleanup, parallel)
                                  │
  P0 ─┬─ L-001  migrations
      │
      └─ L-002  BotPaymentService
                    │
                    ▼
  P1 ── L-003  ledger_entries schema
             │
             ├─ L-004  RecordLedgerEntry + DTO + enums
             │        │
             │        ├─ L-005  event + projection updater skeleton
             │        │        │
             │        │        └─ L-011  balance projections
             │        │                 │
             │        │                 └─ L-013  LedgerReportService
             │        │                          │
             │        │                          └─ L-014  Filament reads from service
             │        │
             │        ├─ L-006  source adapters (3)
             │        │        │
             │        │        ├─ L-007  Beds24 migrated (flagged)
             │        │        ├─ L-008  Octo migrated (kill legacy path)
             │        │        ├─ L-009  bots migrated (flagged)
             │        │        └─ L-010  Filament pages migrated
             │        │
             │        └─ L-012  payment projections (read-only models)
             │
             ├─ L-015  backfill command
             │
             ├─ L-016  consolidate reconciliation
             │
             ├─ L-017  CI guard
             │
             ├─ L-018  write firewall (runtime guard)
             │
             ├─ L-019  freeze legacy tables (triggers)
             │
             └─ L-020  drop legacy tables (after observation window)
```

**Hard gate:** L-001 and L-002 must be merged to `main` and deployed before any L-003+ ticket begins.

---

## 3. 🔴 P0 tickets — blockers

### L-001 · Resolve 3 duplicate `Schema::create` migrations            P0   Risk: HIGH

**Scope:**
- `database/migrations/2025_03_04_033652_create_guest_payments_table.php`
- `database/migrations/2026_04_17_000002_create_guest_payments_table.php`
- `database/migrations/2026_03_28_130000_create_booking_fx_syncs_table.php`
- `database/migrations/2026_03_29_100001_create_booking_fx_syncs_table.php`
- `database/migrations/2026_03_28_130001_create_fx_manager_approvals_table.php`
- `database/migrations/2026_03_29_100003_create_fx_manager_approvals_table.php`
- new: `database/migrations/{stamp}_consolidate_duplicate_schema_migrations.php`
- new: `docs/architecture/MIGRATION_HISTORY.md`

**Why:** `DOMAINS.md §B6`, `MONEY_FLOW_DEEP_DIVE.md §0.4`. Fresh `migrate:fresh` fails; production runs on whichever migration ran first. Two schemas for `guest_payments` — 13 months apart, **completely different shape** (v1: `guest_id`+`booking_id`; v2: `booking_inquiry_id`+refund semantics).

**Steps:**
1. On VPS, dump live schema for all 3 tables: `SHOW CREATE TABLE guest_payments\G` etc. Compare with both migration files. Determine which migration won.
2. For each table, write a **consolidation migration** that:
   - Introspects current shape via `Schema::hasColumn(...)` / `DB::select('SHOW COLUMNS ...')`
   - If current schema matches the "winner" — no-op (it's an informational migration)
   - If current schema is the "loser" — transforms data to the winner shape in one transaction (copy rows, swap columns, drop old)
3. Mark the "loser" migration files as deprecated:
   - Rename to `*.php.deprecated`
   - Add a no-op body with comment pointing to the consolidation migration
4. Create `docs/architecture/MIGRATION_HISTORY.md` recording the decision for future maintainers.
5. Verify `php artisan migrate:fresh --seed` works on a clean local MySQL.

**Depends on:** none.

**Test plan:**
- Local: `migrate:fresh` → no errors.
- Staging: migration applied to a DB snapshot from production → identical row count before/after.
- Spot check: random sample of 20 `guest_payments` rows reconcile pre/post by `id` — no data loss.

**Rollback:** Consolidation migrations are transactional. If the transformation fails mid-run it rolls back. If a logic bug is discovered post-deploy, restore from the pre-migration backup (mandatory to take before running).

**Effort:** **M (2–3 days)** — most time in diffing schemas and writing/testing the introspective migration. Guest_payments alone may need real transformation.

---

### L-002 · Collapse `BotPaymentService` duplicates                   P0   Risk: HIGH

**Scope:**
- `app/Services/BotPaymentService.php` (~800 LOC estimated)
- `app/Services/Fx/BotPaymentService.php` (244 LOC)
- All callers — `grep -rnE 'BotPaymentService' app/`

**Why:** `MONEY_FLOW_DEEP_DIVE.md §2.4, §2.5`. Two classes, same basename, both create `CashTransaction` with presentation snapshot + override chain. Live wiring ambiguous — caller-dependent, split-brain risk.

**Steps:**
1. Enumerate all importers: `grep -rnE "use App\\\\Services(\\\\Fx)?\\\\BotPaymentService" app/`. Tabulate which class each caller imports.
2. Produce a side-by-side diff document at `docs/architecture/botpaymentservice-diff.md` — capture every divergence (method signatures, exceptions, DB write columns, FX snapshot fields).
3. Decide canonical copy per decision #1 (`app/Services/` top-level). Confirm it has the richer feature set (group audit, presentation DTO) — if the `Fx/` copy has a unique capability, port it in.
4. In one PR:
   - Merge any `Fx/` capabilities missing from top-level into the top-level class.
   - Update all callers that import the `Fx/` path to import the top-level one.
   - Delete `app/Services/Fx/BotPaymentService.php`.
   - Write a targeted regression test for each merged capability.
5. Run `grep -rnE "Fx\\\\BotPaymentService" app/` — must return zero.

**Depends on:** L-001 (don't refactor code until migration integrity is clean).

**Test plan:**
- Unit: each public method of the canonical class has a test.
- Integration: full cashier-bot "pay booking" flow end-to-end, single-booking **and** group-booking scenarios — both produce correct `cash_transactions` row.
- Integration: override/approval flow exercised.
- Regression: `tests/Unit/BotPaymentServiceOverrideTest.php` (exists per Phase 1) must still pass.

**Rollback:** Git revert on the PR. The canonical class plus the merge was additive until the deletion — revert restores both.

**Effort:** **L (4–7 days)** — careful diffing, capability merging, comprehensive tests for a money-critical class.

---

## 4. 🟡 P1 tickets — core refactor

### L-003 · Create `ledger_entries` table + `LedgerEntry` model      P1   Risk: LOW

**Scope:**
- new: `database/migrations/{stamp}_create_ledger_entries_table.php`
- new: `app/Models/LedgerEntry.php`
- new: `app/Enums/LedgerEntryType.php`
- new: `app/Enums/SourceTrigger.php` (consolidates existing `CashTransactionSource`)
- new: `app/Enums/PaymentMethod.php`
- new: `app/Enums/CounterpartyType.php`
- new: `app/Enums/TrustLevel.php`
- new: `app/Enums/LedgerEntryDirection.php`
- new: `app/Enums/LedgerDataQuality.php`

**Why:** `TARGET_ARCHITECTURE.md §3`. Foundation — everything else depends on this being present.

**Steps:**
1. Write migration per `TARGET_ARCHITECTURE.md §3.1` (immutable, ULID, idempotent, polymorphic counterparty, reversal/parent linkage). Add all indexes listed.
2. Write model with:
   - `protected $fillable` listing every attribute
   - `protected $casts` mapping columns to enums
   - `use HasUlids` for the `ulid` column (not the primary key)
   - `static::updating(fn () => throw new LedgerImmutableException(...))` → block all updates
   - `static::deleting(fn () => throw new LedgerImmutableException(...))` → block all deletes
3. Define enums per `TARGET_ARCHITECTURE.md §3.3–3.5`.
4. Map old `CashTransactionSource` values as aliases on `SourceTrigger` for backward reading (`'cashier_bot' → CashierBot`).
5. No code writes to this table yet — it's a dormant foundation.

**Depends on:** L-001, L-002.

**Test plan:**
- Migration runs cleanly; `migrate:fresh` green.
- Model unit tests:
  - `LedgerEntry::create([...])` from outside `Actions\Ledger\*` **throws** (enforced in L-018, scaffolded here)
  - `LedgerEntry::find($id)->update(...)` throws
  - `LedgerEntry::find($id)->delete()` throws
  - Every enum column round-trips correctly

**Rollback:** Drop table + remove files. Zero impact on live flows.

**Effort:** **M (2–3 days)**.

---

### L-004 · `RecordLedgerEntry` action + `LedgerEntryInput` DTO + validation      P1   Risk: LOW

**Scope:**
- new: `app/Actions/Ledger/RecordLedgerEntry.php`
- new: `app/DTOs/Ledger/LedgerEntryInput.php`
- new: `app/DTOs/Ledger/PresentationSnapshot.php`
- new: `app/Exceptions/Ledger/LedgerImmutableException.php`
- new: `app/Exceptions/Ledger/LedgerIdempotencyConflictException.php`
- new: `app/Exceptions/Ledger/InvalidLedgerEntryException.php`

**Why:** `TARGET_ARCHITECTURE.md §4.1`. The single contract.

**Steps:**
1. Define `LedgerEntryInput` DTO — typed, constructor-injected, no mutations.
2. `LedgerEntryInput::validate()` enforces:
   - `amount > 0`
   - `currency` in `Currency` enum
   - `direction` matches expected sign for `entry_type` (e.g. `AccommodationPaymentIn` → `in`)
   - `shift_id` provided → shift exists and is open
   - `reverses_entry_id` provided → original entry exists and is not itself a reversal
   - `parent_entry_id` provided → parent exists and is in same currency on opposite direction
   - `external_reference` required for `authoritative` sources
3. `RecordLedgerEntry::execute(LedgerEntryInput)`:
   - Fast-path idempotency check (outside transaction)
   - `DB::transaction` wrapper
   - ULID generation
   - `LedgerEntry::create` using a privileged binding (see L-018)
   - Dispatch `LedgerEntryRecorded` event
   - Return the entry
4. Add structured logging of every call (source, entry_type, amount, currency, idempotency_key).

**Depends on:** L-003.

**Test plan:**
- **Idempotency:** call twice with same `(source, idempotency_key)` → returns same entry; no duplicate row.
- **Idempotency conflict:** call twice with same key but different payload → throws `LedgerIdempotencyConflictException`.
- **Validation:** every invariant has a failing-input test.
- **Transaction:** mock the event listener to throw; verify entry row is rolled back.
- **Concurrency:** parallel calls with same idempotency key → one wins, one returns existing.

**Rollback:** Git revert. No production caller yet.

**Effort:** **M (3 days)**.

---

### L-005 · `LedgerEntryRecorded` event + projection updater skeleton      P1   Risk: LOW

**Scope:**
- new: `app/Events/Ledger/LedgerEntryRecorded.php`
- new: `app/Listeners/Ledger/UpdateBalanceProjections.php` (sync)
- new: `app/Listeners/Ledger/UpdateAggregateProjections.php` (queued)
- new: `app/Console/Commands/LedgerRebuildProjections.php`
- new: `app/Projections/` folder with README

**Why:** `TARGET_ARCHITECTURE.md §5.1`, decision #2 (hybrid refresh mode: sync balances, queued aggregates).

**Steps:**
1. Event is a simple DTO wrapping the inserted `LedgerEntry`.
2. Synchronous listener updates single-row projections (drawer balance, shift balance) — runs in-process so UI reads are never stale.
3. Queued listener updates aggregates (daily cash flow, report caches). Use Laravel queues already present.
4. `ledger:rebuild-projections {--from} {--to} {--projections=}` — iterates `ledger_entries` in `occurred_at` order and re-runs the listeners; truncates target projection tables first when `--full`.
5. Skeleton only — concrete projection tables land in L-011/L-012.

**Depends on:** L-004.

**Test plan:**
- Action call fires event; listeners invoked.
- `rebuild-projections --dry-run` shows expected writes without persisting.
- Rebuild is idempotent — running twice produces the same projection rows.

**Rollback:** Disable event subscriber in `EventServiceProvider`. No production readers yet.

**Effort:** **M (3 days)**.

---

### L-006 · External-source adapter actions (Beds24 / Octo / GYG)      P1   Risk: LOW

**Scope:**
- new: `app/Actions/Ledger/IngestBeds24Payment.php`
- new: `app/Actions/Ledger/IngestOctoPayment.php`
- new: `app/Actions/Ledger/IngestGygPrePaidBooking.php`

**Why:** `TARGET_ARCHITECTURE.md §4.2`. Translate external payloads → `LedgerEntryInput`. ≤100 LOC each.

**Steps:**
1. Each adapter accepts a source-specific DTO (e.g. `IngestBeds24Payment::execute(Beds24PaymentPayload $payload)`).
2. Builds the `LedgerEntryInput` with `source=Beds24Webhook` / `OctoCallback` / `GygImport`, correct `trust_level`, proper idempotency key.
3. **L-008 also fixes Octo amount drift** — `IngestOctoPayment` takes `actualPaidAmount` explicitly, forbidding `price_quoted` as input.
4. Calls `RecordLedgerEntry::execute()`. No other side effects.
5. Not wired to controllers yet — L-007/L-008/L-010 do that.

**Depends on:** L-004.

**Test plan:**
- Per adapter: feed realistic source payload (captured from staging), assert resulting `LedgerEntry` matches expected.
- Idempotency: same payload twice → one entry.
- `price_quoted` substitute test for Octo: verify it's impossible to pass it; real `paidSum` must be provided.

**Rollback:** Delete files. Not wired yet.

**Effort:** **M (3 days)**.

---

### L-007 · Migrate `Beds24WebhookController` to `IngestBeds24Payment` (flagged)      P1   Risk: MEDIUM

**Scope:**
- `app/Http/Controllers/Beds24WebhookController.php` — reduce from 1,189 LOC to ≤150 LOC
- new: feature flag `features.beds24.use_ledger_adapter` (default false)

**Why:** `MONEY_FLOW_DEEP_DIVE.md §2.8`. Controller creates `CashTransaction` directly at `:693` with ad-hoc dedup logic.

**Steps:**
1. Add feature flag (config + env).
2. Controller branches:
   - Flag off → existing code (legacy path remains)
   - Flag on → extract payload to DTO → call `IngestBeds24Payment`
3. The legacy `alertViolation()` logic for cash-method-via-Beds24 moves into the adapter's post-hook, so the behaviour is preserved.
4. Enable the flag in staging first. Soak for 7 days with `WebhookReplay` command hitting both paths and comparing row output (shadow mode).
5. Enable in production.

**Depends on:** L-006.

**Test plan:**
- `WebhookReplay` command reproduces 30 days of Beds24 webhook payloads against both code paths — diff of resulting rows must be zero (modulo `id`, `created_at`, `ulid`).
- `CashTransaction` rows created (legacy) and `LedgerEntry` rows created (new, via backfill compat in L-015) produce same money truth.
- Staging soak 7 days — zero error rate above baseline.

**Rollback:** Set flag to false — immediate revert to legacy code.

**Effort:** **L (5 days)**.

---

### L-008 · Migrate `OctoCallbackController` + kill legacy Booking path + fix amount drift      P1   Risk: HIGH

**Scope:**
- `app/Http/Controllers/OctoCallbackController.php` — reduce to ≤100 LOC
- Delete `handleBookingCallback()` method (legacy `Booking` path, 2.9 in money deep-dive)
- Adjust `routes/web.php:119` route — ensure only `handleInquiryCallback` remains reachable

**Why:** Decision #4 (kill legacy). `MONEY_FLOW_DEEP_DIVE.md §2.9 (BROKEN)` + §2.10 (RISKY amount drift) — both fixed in one ticket.

**Steps:**
1. **Pre-check:** verify production has no `Booking.payment_status` rows updated in the last 90 days via Octo. If any, list and export them. Decide whether any remediation is needed.
2. Feature flag `features.octo.use_ledger_adapter` (default off for staging rollout).
3. Rewrite `handle()` to:
   - Identify inquiry by `octo_transaction_id` (existing logic at `:110`).
   - Run guards (idempotency via `paid_at`, terminal-status check).
   - Build DTO with **`$paidSum` from Octo** (not `price_quoted`).
   - Call `IngestOctoPayment`.
   - Fire notifier.
4. Remove `handleBookingCallback` entirely, including `preg_match` regex parsing.
5. Deploy behind flag; staging soak with sandbox Octo.
6. Switch flag in production within a low-traffic window. Monitor `ledger_entries` inserts in real time for 48h.

**Depends on:** L-006, L-007 (to establish the flag pattern).

**Test plan:**
- **Amount correctness:** feed 3 synthetic Octo callbacks with `price_quoted=100, paid_sum=95/100/105` — resulting entry `amount` = 95/100/105 respectively (not 100 in all three).
- **Idempotency:** duplicate webhook replay → single entry.
- **Terminal-status guard:** call on a cancelled inquiry → `ReconciliationAdjust` entry created, inquiry status not silently revived.
- **Legacy route:** any attempt to hit the old `handleBookingCallback` path (via manually-crafted transaction_id format `booking_123_XX`) now rejected.

**Rollback:**
- Flag off — reverts to legacy controller code
- Legacy `handleBookingCallback` stays in git history; if a critical case emerges, it can be re-added within 1h

**Effort:** **L (5–7 days)** — careful because it's the revenue path.

---

### L-009 · Migrate bot controllers to adapter actions (flagged per bot)      P1   Risk: MEDIUM

**Scope:**
- `app/Http/Controllers/CashierBotController.php` — 1,819 LOC → ≤400 LOC
- `app/Http/Controllers/TelegramPosController.php` — 1,492 LOC → ≤400 LOC
- `app/Http/Controllers/OwnerBotController.php` — 276 LOC → ≤150 LOC
- new: `app/Actions/Ledger/RecordCashReceived.php`
- new: `app/Actions/Ledger/RecordCashExpense.php`
- new: `app/Actions/Ledger/RecordCashDeposit.php`
- new: `app/Actions/Ledger/RecordCurrencyExchange.php`
- new: `app/Actions/Ledger/ReverseLedgerEntry.php`
- new: `app/Services/Telegram/BotCommandDispatcher.php` (shared intent-routing kernel — reduces per-bot duplication)
- feature flags: `features.ledger.bots.cashier`, `features.ledger.bots.pos`, `features.ledger.bots.owner`

**Why:** `MONEY_FLOW_DEEP_DIVE.md §2.4–2.7`. Controllers write money directly at `:1065` (cashier), `:141` (owner), plus the duplicated BotPaymentService calls from POS.

**Steps:**
1. Extract a thin `BotCommandDispatcher` service — shared intent routing and session handling. Each bot controller keeps its UI/keyboard specifics but delegates business writes to the dispatcher → adapter.
2. One bot at a time (cashier → POS → owner):
   - Add flag.
   - Branch old vs new path inside each intent handler.
   - New path: build DTO, call appropriate adapter action.
   - Enable in staging, soak, enable in production.
3. `ReverseLedgerEntry` replaces `OwnerBotController::reverseExpenseTransaction:125` — the reversal now carries `reverses_entry_id` FK, no more fuzzy match.
4. `RecordCurrencyExchange` replaces `CashierExchangeService` — produces two entries linked by `parent_entry_id`, not a shared reference string.

**Depends on:** L-006.

**Test plan:**
- Per bot, replay 30 days of bot callbacks (from `telegram_processed_callbacks`) through both paths — diff zero.
- Shift lifecycle: open → receive → expense → exchange → close → reversal — produces coherent ledger state.
- Duplicate callback (same `callback_query_id` replayed) → one entry.

**Rollback:** Per-bot flag toggled off.

**Effort:** **XL (2 weeks)** — three fat controllers, shared kernel extraction, heaviest user-facing path.

---

### L-010 · Migrate Filament money writes to adapters      P1   Risk: MEDIUM

**Scope:**
- `app/Filament/Pages/TourCalendar.php:160` (`quickGuestPay`) — route to `RecordCashReceived` or `IngestOctoPayment` by `payment_method`
- `app/Filament/Pages/TourCalendar.php:432` (`quickPay` supplier) — route to new `RecordSupplierPayment`
- `app/Filament/Resources/BookingInquiryResource.php:1376` (`markPaid` action) — route to `RecordCashReceived` or similar
- `app/Filament/Resources/CashTransactionResource/Pages/CreateCashTransaction.php` — replace with a `RecordManualAdjustmentPage` that calls `RecordLedgerEntry` directly (as `ManualAdjustment` type)
- new: `app/Actions/Ledger/RecordSupplierPayment.php`
- new: `app/Actions/Ledger/RecordManualAdjustment.php`
- feature flag: `features.ledger.filament.enabled`

**Why:** Decision #8 + absolute rule. `MONEY_FLOW_DEEP_DIVE.md §2.11–2.14`.

**Steps:**
1. Add flag.
2. For each Filament button/action, branch:
   - Flag off → existing `Model::create(...)` code
   - Flag on → call adapter action with `source=FilamentAdmin`, `created_by_user_id=auth()->id()`
3. `CreateCashTransaction` page is **replaced**, not adapted — the page becomes a `RecordManualAdjustmentPage` with purpose-built form that requires justification (`notes` mandatory) for `trust_level=manual` entries.
4. Soak in staging → enable in production.

**Depends on:** L-004, L-006, L-012 (projection read models must be live so Filament reads still work after writes migrate).

**Test plan:**
- Create each payment type through each Filament page → projection row appears identical to legacy.
- Admin creates a manual adjustment → ledger entry has `data_quality='ok'` (not `manual_review`) and proper `notes`.
- RBAC: non-admin cannot access `RecordManualAdjustmentPage`.

**Rollback:** Flag off.

**Effort:** **L (5–7 days)**.

---

### L-011 · Balance projections (sync)      P1   Risk: LOW

**Scope:**
- new tables: `cash_drawer_balances`, `shift_balances`
- new: `app/Projections/CashDrawerBalanceProjection.php`
- new: `app/Projections/ShiftBalanceProjection.php`
- `UpdateBalanceProjections` listener from L-005 is implemented

**Why:** `TARGET_ARCHITECTURE.md §5.2`. Today these are computed ad-hoc in widgets and `getBal()` in `CashierBotController`.

**Steps:**
1. Migrations for projection tables.
2. Projection classes with `handle(LedgerEntry $entry)` — called synchronously by the listener.
3. Verify idempotent: processing the same entry twice does not double-count. Key: projection rows keyed on `(drawer_id, currency)` with a running `balance`; applying the same `ledger_entry.id` twice requires a `last_applied_entry_id` cursor to skip.
4. `rebuild-projections` command truncates and re-runs.

**Depends on:** L-005.

**Test plan:**
- Insert a series of ledger entries; read projection; assert balance = sum with sign.
- Rebuild twice → same rows.
- Reversal: insert entry then reversal; projection balance returns to pre-entry state.

**Rollback:** Drop projection tables. Listener skipped. UI falls back to legacy code until re-enabled.

**Effort:** **M (3 days)**.

---

### L-012 · Payment projections (read-only facade over legacy models)      P1   Risk: MEDIUM

**Scope:**
- `app/Models/GuestPayment.php` — convert to read-only facade
- `app/Models/SupplierPayment.php` — same
- `app/Models/CashExpense.php` — same
- `app/Models/Expense.php`, `app/Models/AgentPayment.php`, `app/Models/DriverPayment.php` — same
- new tables: `guest_payment_view`, `supplier_payment_view`, `expense_view` — OR keep existing tables during coexistence and switch to view-over-ledger post-backfill
- new: `app/Projections/GuestPaymentProjection.php` etc.

**Why:** Absolute rule: no money model without ledger mapping. `DOMAINS.md §A11` (orphan models with zero service callers).

**Steps:**
1. During coexistence, two options per model:
   - **Option A (preferred):** existing table stays; projection keeps it in sync from `LedgerEntry`. Readers unchanged.
   - **Option B:** drop old table; recreate as `VIEW guest_payment_view AS SELECT ... FROM ledger_entries WHERE entry_type IN (...)`.
2. Pick Option A — less disruptive during coexistence.
3. Model becomes read-only:
   - `static::creating(fn () => throw new LedgerOnlyException(...))`
   - Same for `updating`, `deleting`
4. Projection listener writes `GuestPayment` row when a matching `LedgerEntry` is recorded. Handles insertion and reversal (create cancellation row / soft flag on projection — not on ledger).
5. Backfill handled by L-015.

**Depends on:** L-005, L-010 (all direct writes must be gone first; otherwise the read-only guard blocks legitimate writes).

**Test plan:**
- Write to `GuestPayment` from any non-projection path → exception.
- Record matching `LedgerEntry` → `GuestPayment` row appears with same amounts.
- Filament reading these models shows identical numbers to pre-change.

**Rollback:** Remove the read-only guards on the models; disable the projection listener. Legacy flows resume.

**Effort:** **L (5 days)** — six models, subtle read-only enforcement, parallel-read testing.

---

### L-013 · `LedgerReportService` — consolidate all financial reporting math      P1   Risk: MEDIUM

**Scope:**
- new: `app/Services/Ledger/LedgerReportService.php`
- Port from: `app/Services/AdvancedReportService.php` (506 LOC), `app/Services/DailyRecapBuilder.php`, ad-hoc SQL in widgets and Filament pages
- new: `app/DTOs/Report/DailyCashFlow.php`, `GuestBalance.php`, `SupplierBalance.php`, `MonthlyReport.php`

**Why:** `TARGET_ARCHITECTURE.md §5.4`. Today, numbers are computed in multiple places with drift potential. `MONEY_FLOW_DEEP_DIVE.md §3.5` — two parallel ledgers producing different answers.

**Steps:**
1. Implement interface per §5.4: `dailyCashFlow`, `guestBalance`, `supplierBalance`, `drawerBalance`, `reconciliationDrift`, `monthlyFinancialSummary`.
2. Each method queries projections only — never raw `ledger_entries` except for `reconciliationDrift`.
3. Shadow-compare against `AdvancedReportService` for 7 days: same inputs → same outputs within ±0.01.
4. `AdvancedReportService` marked `@deprecated`. Still callable until L-014 migrates readers.

**Depends on:** L-011, L-012.

**Test plan:**
- Shadow comparison fixture: snapshot of 30 days of bookings/payments → old and new service produce identical numbers.
- Property-based test: random ledger entry sets → drawer balance via service = manual sum with sign.
- Performance: report generation for 30 days completes under 2s on staging.

**Rollback:** Readers haven't migrated yet; delete the new service file.

**Effort:** **L (5–7 days)**.

---

### L-014 · Migrate all Filament readers to `LedgerReportService`      P1   Risk: MEDIUM

**Scope:**
- `app/Filament/Pages/CashDashboard.php`
- `app/Filament/Pages/BookingsReport.php`
- `app/Filament/Pages/GuestBalances.php`
- `app/Filament/Pages/SupplierBalances.php`
- `app/Filament/Pages/ExpenseReports.php`
- `app/Filament/Pages/Reports.php` (680 LOC)
- `app/Filament/Pages/TourCalendar.php` (725 LOC) — read portions
- All widgets in `app/Filament/Widgets/`

**Why:** Decision + absolute rule enforcement. Phase 3.5 §3 — "math in views".

**Steps:**
1. Widget by widget, page by page — replace inline SQL with `LedgerReportService` call.
2. Visual regression: take a fe-snapshot of each page with a known date range pre-migration, compare post-migration.
3. Retire `AdvancedReportService` after all callers migrated. Delete file.

**Depends on:** L-013.

**Test plan:**
- Visual snapshot diff per page (fe-snapshot tool) with matching date filters — no material differences.
- Numerical comparison: export CSV from each report pre/post — diff empty (or within ±0.01).

**Rollback:** Revert individual widget PRs. Legacy service still alive.

**Effort:** **L (5 days)**.

---

### L-015 · Backfill historical data into `ledger_entries`      P1   Risk: MEDIUM

**Scope:**
- new: `app/Console/Commands/LedgerBackfill.php`
- new: `app/Services/Ledger/LegacyBackfillMapper.php` — the row-translation logic

**Why:** `MONEY_FLOW_DEEP_DIVE.md §6` — reconstruction plan. `TARGET_ARCHITECTURE.md §7.4`.

**Steps:**
1. Command signature: `ledger:backfill {--from=} {--to=} {--dry-run} {--chunk=1000} {--source=all}`
2. Iteration order: `cash_transactions` → `guest_payments` → `supplier_payments` → `cash_expenses` → `beginning_saldos` / `end_saldos`.
3. Each source:
   - Maps row to `LedgerEntryInput` per §7.2 table in target architecture
   - Uses `external_reference = 'legacy:cash_transactions:{id}'` (or equivalent) + unique constraint for idempotency
   - Preserves original `source_trigger` in `tags` for audit
   - Reversal reference parsing: where `reference LIKE 'reversal:%'` → set `reverses_entry_id`. Where fuzzy match was used → mark `data_quality='manual_review'`.
   - Exchange pair reconstruction: group by `reference LIKE 'EX-%'` → set `parent_entry_id` on the second leg.
4. Post-backfill verification:
   - `ledger_entries.source=SystemBackfill` count = combined legacy row count
   - `SUM(amount * direction_sign)` per projection matches pre-backfill computed balance

**Depends on:** L-004, L-011, L-012.

**Test plan:**
- Dry-run on staging with production snapshot → produces a report of entries to create, no writes.
- Full run on staging → projection balances match pre-backfill snapshot within ±0.01 for all drawers.
- Idempotent: running twice does not duplicate rows.
- `data_quality='manual_review'` flagged rows are reported in a summary file.

**Rollback:** `DELETE FROM ledger_entries WHERE source='system_backfill'` — the source stamp makes backfilled rows identifiable. Projections rebuild from remaining rows.

**Effort:** **XL (1–2 weeks)** — data mapping is laborious and every edge case matters.

---

### L-016 · Consolidate reconciliation onto ledger projections      P1   Risk: MEDIUM

**Scope:**
- `app/Services/ReconciliationService.php` — refactor to query projections, not `Beds24Booking.invoice_balance` directly
- `app/Services/Fx/WebhookReconciliationService.php` — absorbed, then deleted

**Why:** Decision #2. `MONEY_FLOW_DEEP_DIVE.md §2.16` — the service itself declares Beds24 is the truth; now that the ledger exists, the service can reconcile **both** the projection and Beds24, surfacing drift.

**Steps:**
1. Redefine reconciliation output: `(booking, expected_from_beds24, ledger_projection_sum, drift)`.
2. Where drift exists, emit a `ReconciliationAdjust` ledger entry (per `LedgerEntryType` §3.3).
3. Migrate `RunReconciliation` command to call the consolidated service.
4. Delete `Fx/WebhookReconciliationService` and any dedicated migration.

**Depends on:** L-011, L-015.

**Test plan:**
- Fixture: 10 bookings with deliberately diverged internal vs Beds24 totals → 10 `ReconciliationAdjust` entries created.
- Drift-free case: no adjustment entries emitted.
- Weekly reconcile output matches pre-cutover baseline.

**Rollback:** Keep both services coexisting temporarily; revert the consolidated service.

**Effort:** **M (3–4 days)**.

---

### L-017 · CI guard — reject direct ledger writes in PRs      P1   Risk: LOW

**Scope:**
- new: `bin/check-ledger-discipline.sh`
- CI config updated (GitHub Actions / whatever is in use)

**Why:** `TARGET_ARCHITECTURE.md §6.3`. Hard gate to prevent regression.

**Steps:**
1. Implement script per §6.3 — greps for forbidden patterns, exits non-zero with offending `file:line`.
2. Wire into CI so any PR introducing a forbidden write blocks merge.
3. Grace list for the duration of L-007..L-010: active migration branches may write to legacy tables; remove grace list once those tickets complete.

**Depends on:** L-004 (so `Actions/Ledger/` exists as the allowed path).

**Test plan:**
- Dummy branch introduces `LedgerEntry::create` outside allowed path → CI fails.
- Same for any projection model direct write.
- Known-good branch passes.

**Rollback:** Remove the CI hook. Non-destructive.

**Effort:** **S (1 day)**.

---

### L-018 · Runtime write firewall (user-added rule)      P1   Risk: MEDIUM

**Scope:**
- `app/Models/LedgerEntry.php` — boot hook
- `app/Actions/Ledger/RecordLedgerEntry.php` — binds `'ledger_context'` in app container for the duration of the write
- Middleware for console: `LedgerContextMiddleware` for backfill / rebuild commands

**Why:** User directive: *runtime guard blocks direct writes*. Pair to the CI guard (L-017) — CI catches code, firewall catches runtime.

**Steps:**
1. In `LedgerEntry::boot()`, register a `creating` hook that checks `app()->bound('ledger_context')`; if absent → throw `LedgerImmutableException`.
2. `RecordLedgerEntry::execute()` binds the context for the scope of its `DB::transaction`:
   ```php
   app()->instance('ledger_context', new LedgerContext(...));
   try {
       // ... write
   } finally {
       app()->forgetInstance('ledger_context');
   }
   ```
3. Backfill / rebuild commands bind the context explicitly at command start, release at end.
4. Ship behind feature flag `features.ledger.firewall.enforce` (default `false` until all callers migrated); flip to `true` at cutover.

**Depends on:** L-004, L-007, L-008, L-009, L-010 (every legitimate writer must route through the context before the firewall is enforced).

**Test plan:**
- Any direct `LedgerEntry::create` outside the context → exception.
- `RecordLedgerEntry` → succeeds.
- Backfill command → succeeds.
- Concurrent writes each with own context binding — no cross-contamination.

**Rollback:** Disable the feature flag. Firewall becomes inert.

**Effort:** **M (2–3 days)**.

---

### L-019 · Freeze legacy tables via DB triggers      P1   Risk: HIGH

**Scope:**
- Triggers on: `cash_transactions`, `guest_payments`, `supplier_payments`, `cash_expenses`, `agent_payments`, `driver_payments`, `expenses`
- SQL migration: create triggers that raise on `INSERT`/`UPDATE`/`DELETE`

**Why:** `TARGET_ARCHITECTURE.md §7.1`. Belt-and-braces freeze after CI + firewall + projection migration.

**Steps:**
1. MySQL triggers: `BEFORE INSERT ... SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Legacy table frozen; use ledger_entries'`
2. Same for UPDATE, DELETE.
3. Deploy only after:
   - All writers migrated (L-007 through L-010 done)
   - Firewall enforced (L-018 `features.ledger.firewall.enforce=true`)
   - Backfill verified (L-015 green)
   - Projections stable 7 days (L-011, L-012, L-013, L-014 done)
   - CI guard live (L-017)

**Depends on:** L-007, L-008, L-009, L-010, L-012, L-014, L-015, L-017, L-018.

**Test plan:**
- Attempt to write to frozen table from tinker → fails with message.
- Existing SELECT queries continue to work.
- Full reconciliation run green.

**Rollback:** Drop the triggers. Takes seconds; restores writability.

**Effort:** **M (2 days)**.

---

### L-020 · Drop legacy tables (observation window + destructive)      P1   Risk: HIGH

**Scope:** destructive migration dropping `cash_transactions`, `guest_payments`, `supplier_payments`, `cash_expenses`, `agent_payments`, `driver_payments`, `expenses` — **only after the observation window closes successfully**.

**Why:** Ledger and projections supersede.

**Steps:**
1. Observation window: 30 days post-L-019 with cutover gates green (see §5).
2. Take full database backup, archive externally.
3. Run drop migration.
4. Delete corresponding models (after marking deprecated for one further release).
5. Update `MIGRATION_HISTORY.md`.

**Depends on:** L-019 + 30 days observation + explicit user sign-off.

**Test plan:**
- Pre-drop backup restored in staging → all data intact.
- Post-drop staging: every app path exercised — no 500s, no missing-column errors.

**Rollback:** Restore from backup (operational — hours of downtime expected). **This is the one ticket without an in-flight rollback.**

**Effort:** **M (2 days of execution, 30 days of waiting)**.

---

## 5. Cutover gates (must all be green to proceed past L-019)

From `TARGET_ARCHITECTURE.md §7.6`:

| # | Gate | Measured how |
|---|---|---|
| 1 | Zero direct `LedgerEntry::create` outside `app/Actions/Ledger/` | CI (L-017) reports clean for 7 days |
| 2 | Zero direct writes to projection models outside `app/Projections/` | Same |
| 3 | `ledger:rebuild-projections --verify` zero drift for 7 consecutive days | Scheduled diff job, logged |
| 4 | `ReconciliationService` runs green for 7 consecutive days | Daily recap email |
| 5 | Daily cash report matches pre-cutover baseline within ±0.01 for 7 consecutive days | Shadow comparison fixture |

**All 5 must be green before L-019 ships. All 5 must stay green for 30 more days before L-020 ships.**

---

## 6. 🟢 P2 tickets — cleanup (run in parallel where non-interfering)

### L-021 · Consolidate DTO / DTOs directories      P2   Risk: LOW

**Scope:**
- Move `app/DTO/*.php` into `app/DTOs/` under appropriate sub-namespaces
- Delete `app/DTO/` folder

**Why:** `INVENTORY.md §4.1` — structural inconsistency (3 files vs 8 files).

**Steps:** 1) move; 2) global rename imports; 3) namespaces follow folder.

**Depends on:** none (independent).

**Test plan:** `composer dump-autoload`, run full test suite.

**Effort:** **S (½ day)**.

---

### L-022 · Delete `.backup` files      P2   Risk: LOW

**Scope:**
- `app/Http/Controllers/VoiceAgentController.php.backup`
- `app/Models/User.php.backup`
- `app/Providers/Filament/AdminPanelProvider.php.backup`
- `app/Services/TelegramKeyboardBuilder.php.backup-20251018-235037`
- `public/enhanced-voice-test.html.bak`

**Why:** `INVENTORY.md §5`. Git history is the backup.

**Steps:** delete; commit.

**Depends on:** none.

**Test plan:** `grep -r '.backup' app/` returns empty; test suite still passes.

**Effort:** **S (5 min)**.

---

### L-023 · Delete `app/Filament/_archived/` (40 files)      P2   Risk: LOW

**Scope:** entire folder.

**Why:** `INVENTORY.md §5.3`. No references; superseded by active resources.

**Steps:** confirm zero references via `grep -r '_archived' app/`; delete; commit.

**Depends on:** none.

**Test plan:** full test suite + Filament admin smoke test in staging.

**Effort:** **S (½ day)** (includes staging verification).

---

### L-024 · Remove deprecated `ScheduledMessage*` (feature disabled)      P2   Risk: LOW

**Scope:**
- `app/Models/ScheduledMessage.php`, `app/Models/ScheduledMessageChat.php`
- `app/Filament/_archived/Resources/ScheduledMessageResource` (already archived)
- `app/Console/Commands/SendScheduledMessagesCommand.php` (already disabled)
- Corresponding migration marked deprecated

**Why:** `INVENTORY.md §11` + code comment `"scheduled_messages table unused; feature deprecated"` at `Console/Kernel.php`.

**Steps:**
1. Confirm zero references in active code.
2. Delete model + command + archived resource.
3. Write migration dropping `scheduled_messages`, `scheduled_message_chats` tables (after backing up to archive).

**Depends on:** none (but deploy the backup strategy first).

**Test plan:** staging — drop tables, no errors; full test suite.

**Effort:** **S (1 day)**.

---

### L-025 · Consolidate `TelegramKeyboardBuilder` + `TelegramKeyboardService`      P2   Risk: LOW

**Scope:** merge the two into one; update callers.

**Why:** `DOMAINS.md §A12`, `INVENTORY.md §4.3` — overlapping responsibility.

**Steps:** diff; keep the richer class; move any unique methods from the other; delete the loser.

**Depends on:** L-009 (after bots are migrated, surface area is smaller).

**Test plan:** full bot flow regression.

**Effort:** **M (2 days)**.

---

### L-026 · Rename `FxSourceTrigger` → `FxRateSyncTrigger`      P2   Risk: LOW

**Scope:** enum rename; update references.

**Why:** `TARGET_ARCHITECTURE.md §3.4` — different axis from `SourceTrigger`. Current name is confusing.

**Steps:** rename class; update all `use`; update DB columns that store it (none directly — it's an in-memory enum per Phase 1 scan).

**Depends on:** L-003 (after new `SourceTrigger` exists).

**Test plan:** test suite passes; grep for old name returns empty.

**Effort:** **S (½ day)**.

---

### L-027 · Remove VoiceAgent remnants      P2   Risk: LOW

**Scope:** `routes/api.php:92-96` (empty group), `VoiceAgentController.php.backup` (also hit by L-022), any orphan config.

**Why:** Phase 1 — feature dead, remnants linger.

**Steps:** delete the empty route group; remove backups; verify.

**Depends on:** L-022 (overlapping file).

**Test plan:** full test suite + routes `php artisan route:list` clean.

**Effort:** **S (½ day)**.

---

### L-028 · Remove `TestJob` (placeholder)      P2   Risk: LOW

**Scope:** `app/Jobs/TestJob.php` (24 LOC).

**Why:** Dev-only placeholder, zero value.

**Steps:** verify zero references; delete.

**Effort:** **S (5 min)**.

---

### L-029 · Introduce Form Requests for fat controllers      P2   Risk: LOW

**Scope:**
- new: `app/Http/Requests/` files for each bot webhook, Octo callback, Beds24 webhook
- controllers `use` them for `request` parameter type

**Why:** `INVENTORY.md §2` — 8 Form Requests for 19 controllers. After L-007/L-008/L-009 reduce controller size, introducing FormRequests becomes practical.

**Steps:** one controller at a time, extract validation → FormRequest, controller becomes `public function handle(MyRequest $request, MyAction $action)`.

**Depends on:** L-007, L-008, L-009, L-010 (wait until controllers are thin).

**Test plan:** full existing webhook replay suite.

**Effort:** **M (3 days across all controllers)**.

---

## 7. Risk register

| Risk | Probability | Impact | Mitigation |
|---|---|---|---|
| Backfill (L-015) misassigns reversals due to fuzzy match | Medium | Medium | `data_quality='manual_review'` flag + Filament review page in FEATURE_BACKLOG |
| Octo migration (L-008) breaks revenue capture | Low | Critical | Flag-gated; sandbox Octo test; 48h monitoring window after prod cutover |
| Filament read migration (L-014) shows different numbers | Medium | High | Shadow comparison with fe-snapshot + CSV diff per page |
| Concurrent writes during L-009 bot migration | Low | Medium | Per-bot flag, staged rollout, `lockForUpdate` in actions |
| Backfill row explosion exceeds storage | Low | Low | ≤1M entries projected; well under MySQL limits |
| Rollback of L-020 requires full DB restore | Low | Critical | 30-day observation window + verified backups before drop |
| CI guard too strict, blocks legitimate new actions | Medium | Low | Allowlist is a config file, not hardcoded — amendable via PR |
| Runtime firewall (L-018) blocks queued projection writes | Low | Medium | Backfill command binds context at start; test listener re-processing |

---

## 8. Ticket summary

| Priority | Count | Combined effort |
|---|---:|---|
| P0 | 2 | ~L (5–10d) |
| P1 core refactor | 18 | ~XL (10–14 weeks cumulative; parallelizable to ~6 weeks) |
| P2 cleanup | 9 | ~M (8–10d) |
| **Total** | **29** | **~8–10 weeks realistic with parallelism** |

---

## 9. What this doesn't cover

Explicitly out of scope for Phase 5; tracked in `FEATURE_BACKLOG.md` or deferred phases:

- **Task core** (Phase 4.5 deep-dive then separate plan): housekeeping, kitchen, maintenance, room issues
- **Tourfirm panel deep audit**: only surfaces in L-010 if it touches money; otherwise defer
- **Session-model consolidation** (`TelegramPosSession`, `TelegramBookingSession`, `OperatorBookingSession`): Phase 6+
- **`Booking` legacy model retirement** (dual-aggregate issue): dependent on Octo cleanup in L-008, full retirement later
- **Room status clarification** (`Room` vs `RoomStatus` vs `RoomPriority`): Phase 6+
- **Laravel 10 → 11 upgrade**: independent track
- **Test coverage expansion**: separate initiative; current 79 test files are baseline

---

## 10. Phase 5 output

- ✅ 29 tickets, each atomic, with scope / why / steps / risk / test / rollback / effort
- ✅ Dependency DAG clear and enforced
- ✅ Cutover gates explicit
- ✅ P0 blockers isolated and small
- ✅ `FEATURE_BACKLOG.md` separately captures enhancement ideas (see companion file)

**Phase 5 complete.** Execution begins with L-001 and L-002 as prerequisites. All other work blocks on them.
