# TARGET ARCHITECTURE — Jahongir Hotel Operations System

**Phase:** 4 · **Generated:** 2026-04-18 · **Commit baseline:** `5f0e5b4`
**Scope of this document:** the **Financial Core** (ledger + actions + projections + enforcement + migration).
**Deferred to a later phase:** Task core (housekeeping/kitchen/maintenance). Requires its own deep-dive — Phase 4.5.

> This document is a **design** — SQL, PHP, and enum shapes are sketches. Phase 5 turns sketches into tickets, Phase 6 executes.

---

## 0. Architectural reframe (post-Phase 3.5)

Before Phase 3.5 the ambition was "build a ledger as the source of truth". That was wrong. Phase 3.5 revealed that **truth already lives in external systems** — the codebase itself acknowledges this (`ReconciliationService.php:16-17`).

**Correct ambition (locked):**

> Build the **ledger as the canonical internal truth layer** that **ingests external authoritative sources** (Beds24, Octo, GYG) and **unifies internal actions** (cash, expenses, exchanges, reversals) into a single consistent event stream against which reports are computed.

### Source trust model

| Source | Trust | Role |
|---|---|---|
| **Beds24** | 🔒 authoritative (external) | PMS — ground truth for accommodation payments, balances, cancellations |
| **Octobank** | 🔒 authoritative (external) | Payment processor — ground truth for online card payments |
| **GYG** | 🔒 authoritative (external) | Channel — ground truth for their pre-paid bookings |
| **Cashier bot** | 🟡 operator input | Real-time cash events from staff in shift |
| **POS bot** | 🟡 operator input | Same, card via POS terminal |
| **Filament (admin)** | 🔴 manual input | Corrections, adjustments, retrospective entries |

All sources produce **ledger entries**. The `source` + `trust_level` columns make the provenance explicit and force reports to treat sources differently when needed.

---

## 1. The 8 locked decisions (authoritative)

| # | Decision |
|---|---|
| 1 | **One `BotPaymentService`** in `app/Services/` — `app/Services/Fx/BotPaymentService.php` is to be deleted |
| 2 | **One reconciliation service** — `ReconciliationService`. Absorb `Fx/WebhookReconciliationService` into it |
| 3 | **Resolve 3 duplicate `Schema::create` migrations** before Phase 5 execution begins — P0 blocker |
| 4 | **`source_trigger` becomes a single enum** — `SourceTrigger` replacing all string literals |
| 5 | **`payment_method` stored as enum/code**, never display strings; display belongs to UI layer |
| 6 | **`GuestPayment.amount` (and any successor) = actual amount reported by the source**, never `price_quoted` |
| 7 | **`cash_transactions` retired and migrated** into `ledger_entries`. Not deleted immediately — source for backfill, kept read-only during coexistence |
| 8 | **Filament forbidden from creating money rows directly.** Filament → Action → ledger |

## 1a. Absolute rule (added by user, elevated)

> **No model can represent money unless it maps to the ledger.**
>
> `GuestPayment`, `SupplierPayment`, `Expense`, `AgentPayment`, `DriverPayment`, `CashExpense` — all become **projections** of `ledger_entries`. They stop being independent writers.

---

## 2. The 3-layer contract (enforced)

From `AUDIT_BRIEF.md §2`, restated in financial terms:

```
┌──────────────────────────────────────────────────────────────────┐
│  PRESENTATION                                                    │
│  HTTP controllers · Filament pages/resources · Bot controllers   │
│  Webhook endpoints · Commands                                    │
│                                                                  │
│  MAY: validate, call actions, format responses                   │
│  MUST NOT: create/update/delete LedgerEntry directly             │
│  MUST NOT: calculate balances, totals, or FX                     │
└────────────────────┬─────────────────────────────────────────────┘
                     │ calls
                     ▼
┌──────────────────────────────────────────────────────────────────┐
│  APPLICATION                                                     │
│  Actions · Services · Jobs · DTOs                                │
│                                                                  │
│  Single entry to the ledger: RecordLedgerEntry action            │
│  Specialized source adapters: IngestBeds24Payment,               │
│  IngestOctoPayment, RecordCashReceived, RecordCashExpense,       │
│  RecordCurrencyExchange, ReverseLedgerEntry                      │
│                                                                  │
│  MUST: wrap every multi-row write in DB::transaction             │
│  MUST: be callable from any entrypoint (HTTP, bot, cron, UI)     │
│  MUST NOT: reach into Request / session / auth()                 │
└────────────────────┬─────────────────────────────────────────────┘
                     │ writes to
                     ▼
┌──────────────────────────────────────────────────────────────────┐
│  DATA                                                            │
│  ledger_entries (append-only)                                    │
│  Projections: cash_drawer_balances, guest_payment_view,          │
│  supplier_payment_view, daily_cash_flow_view                     │
│                                                                  │
│  MUST: be immutable (no UPDATE on ledger_entries)                │
│  MAY: have read-only projections materialized via observers      │
└──────────────────────────────────────────────────────────────────┘
```

---

## 3. The Ledger — data layer design

### 3.1 `ledger_entries` — schema sketch

```sql
CREATE TABLE ledger_entries (
    id                         BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,

    -- Identity & idempotency
    ulid                       CHAR(26) NOT NULL UNIQUE,   -- app-generated ULID for replay/idempotency
    idempotency_key            VARCHAR(191) NULL,          -- source-provided key (e.g. Octo transaction_id, Beds24 payment_ref); UNIQUE NULL-safe

    -- Temporal
    occurred_at                TIMESTAMP NOT NULL,          -- when the event happened in business time
    recorded_at                TIMESTAMP NOT NULL,          -- when the row was written

    -- Taxonomy
    entry_type                 VARCHAR(32) NOT NULL,        -- enum LedgerEntryType (see §3.3)
    source                     VARCHAR(32) NOT NULL,        -- enum SourceTrigger (see §3.4)
    trust_level                ENUM('authoritative','operator','manual') NOT NULL,

    -- Value
    direction                  ENUM('in','out') NOT NULL,   -- always resolved; 'in_out' legs are TWO rows
    amount                     DECIMAL(14, 2) NOT NULL,     -- always positive; direction determines sign
    currency                   CHAR(3) NOT NULL,

    -- FX snapshot (optional, frozen at entry time)
    fx_rate                    DECIMAL(15, 4) NULL,         -- rate applied (currency → base)
    fx_rate_date               DATE NULL,
    daily_exchange_rate_id     BIGINT UNSIGNED NULL,        -- FK to daily_exchange_rates
    presentation_snapshot      JSON NULL,                    -- {uzs, usd, eur, rub, selected_currency}
    usd_equivalent             DECIMAL(14, 2) NULL,

    -- Counterparty (polymorphic but typed)
    counterparty_type          ENUM('guest','supplier','agent','driver','guide','bank','internal','external') NOT NULL,
    counterparty_id            BIGINT UNSIGNED NULL,        -- nullable: external parties may not have internal row

    -- Domain context (nullable FKs — an entry may pertain to one or more)
    booking_inquiry_id         BIGINT UNSIGNED NULL,
    beds24_booking_id          VARCHAR(64) NULL,
    cashier_shift_id           BIGINT UNSIGNED NULL,
    cash_drawer_id             BIGINT UNSIGNED NULL,

    -- Payment method / instrument (enum, not display string)
    payment_method             VARCHAR(32) NOT NULL,        -- enum PaymentMethod (see §3.5)

    -- Override / approval (for high-variance FX flows)
    override_tier              ENUM('none','cashier','manager','blocked') NOT NULL DEFAULT 'none',
    override_approval_id       BIGINT UNSIGNED NULL,
    variance_pct               DECIMAL(6, 2) NULL,

    -- Reversibility / linkage
    parent_entry_id            BIGINT UNSIGNED NULL,         -- leg 2 of a pair (exchange), or expense↔ledger
    reverses_entry_id          BIGINT UNSIGNED NULL,         -- this entry reverses another
    reversed_by_entry_id       BIGINT UNSIGNED NULL,         -- this entry was reversed by another (denormalized for fast lookup; updated by observer on the reverser's insert)

    -- External reference (immutable reference to source-of-record)
    external_reference         VARCHAR(191) NULL,            -- e.g. Beds24 booking id, Octo transaction id, GYG external_reference
    external_item_ref          VARCHAR(191) NULL,            -- e.g. Beds24 payment item id for granular dedup

    -- Authorship
    created_by_user_id         BIGINT UNSIGNED NULL,         -- NULL allowed only when source is external
    created_by_bot_slug        VARCHAR(32) NULL,             -- e.g. 'cashier', 'pos', 'owner'

    -- Audit
    notes                      TEXT NULL,
    tags                       JSON NULL,                    -- ["group-pay","refund","manual-adjustment"]
    data_quality               ENUM('ok','backfilled','manual_review') NOT NULL DEFAULT 'ok',

    -- Timestamps (NO soft deletes — ledger is immutable)
    created_at                 TIMESTAMP NULL,
    -- no updated_at — rows do not update

    INDEX idx_occurred_at (occurred_at),
    INDEX idx_entry_type_occurred (entry_type, occurred_at),
    INDEX idx_source_occurred (source, occurred_at),
    INDEX idx_shift (cashier_shift_id),
    INDEX idx_drawer (cash_drawer_id),
    INDEX idx_booking_inquiry (booking_inquiry_id),
    INDEX idx_beds24_booking (beds24_booking_id),
    INDEX idx_counterparty (counterparty_type, counterparty_id),
    INDEX idx_external_reference (external_reference),
    INDEX idx_parent (parent_entry_id),
    UNIQUE KEY uniq_ulid (ulid),
    UNIQUE KEY uniq_idempotency (source, idempotency_key)
);
```

**Key invariants (enforced in `RecordLedgerEntry` action, and mirrored by a migration CHECK on MySQL 8+):**

- Rows are **immutable**. No `UPDATE`. No `DELETE`. No `soft delete`.
- `amount` is always **positive**. `direction` determines sign in computation.
- `ulid` generated in PHP (`Str::ulid()`) before insert — guarantees ordering and uniqueness.
- `idempotency_key` unique per `source` — prevents double-insertion from retried webhooks.
- A reversal is a **new row** with `reverses_entry_id = X`. The original stays untouched; an observer sets `reversed_by_entry_id = new.id` on the original (projection-only update — the ledger row itself is conceptually immutable; projection denormalization is cosmetic).
- Exchange pairs share `parent_entry_id` (leg 2 points to leg 1; leg 1 has NULL).

### 3.2 Source trust levels

Encoded in the `trust_level` column. Drives:

- **Reporting filters** — reports can exclude `manual` entries when needed
- **Reconciliation** — `authoritative` sources are the anchor; operator/manual entries are matched against them
- **Audit UI** — `manual_review` rows surface in ops dashboard

```
authoritative → Beds24, Octo, GYG
operator      → cashier_bot, pos_bot, owner_bot
manual        → filament_admin
```

### 3.3 `LedgerEntryType` — event taxonomy

Replaces the inconsistent `category` string on `cash_transactions`.

```php
enum LedgerEntryType: string
{
    // Revenue
    case AccommodationPaymentIn = 'accommodation_payment_in';  // guest pays hotel
    case TourPaymentIn          = 'tour_payment_in';           // guest pays tour
    case OtherRevenueIn         = 'other_revenue_in';

    // Refunds
    case AccommodationRefund    = 'accommodation_refund';
    case TourRefund             = 'tour_refund';
    case OtherRefund            = 'other_refund';

    // Payouts
    case SupplierPaymentOut     = 'supplier_payment_out';      // accommodation / driver / guide / vendor
    case AgentCommissionOut     = 'agent_commission_out';
    case StaffPayoutOut         = 'staff_payout_out';

    // Cash-drawer operational
    case CashDrawerOpen         = 'cash_drawer_open';          // beginning saldo
    case CashDrawerClose        = 'cash_drawer_close';         // end saldo
    case CashDeposit            = 'cash_deposit';              // manual admin top-up
    case CashWithdrawal         = 'cash_withdrawal';           // remove from drawer

    // Expenses
    case OperationalExpense     = 'operational_expense';

    // FX
    case CurrencyExchangeLeg    = 'currency_exchange_leg';     // one side of exchange pair

    // Adjustments
    case ReconciliationAdjust   = 'reconciliation_adjust';     // force-match to external truth
    case ManualAdjustment       = 'manual_adjustment';
    case ShiftHandoverAdjust    = 'shift_handover_adjust';
}
```

### 3.4 `SourceTrigger` — unified source enum

Replaces `CashTransactionSource`, `FxSourceTrigger`, and all string literals.

```php
enum SourceTrigger: string
{
    // External (authoritative)
    case Beds24Webhook  = 'beds24_webhook';
    case Beds24Repair   = 'beds24_repair';        // scheduled repair command
    case OctoCallback   = 'octo_callback';
    case GygImport      = 'gyg_import';

    // Internal operator (bot)
    case CashierBot     = 'cashier_bot';
    case PosBot         = 'pos_bot';
    case OwnerBot       = 'owner_bot';

    // Internal manual (admin)
    case FilamentAdmin  = 'filament_admin';

    // Internal automatic
    case ReconcileJob   = 'reconcile_job';
    case SystemBackfill = 'system_backfill';

    public function trustLevel(): string
    {
        return match ($this) {
            self::Beds24Webhook, self::Beds24Repair,
            self::OctoCallback, self::GygImport => 'authoritative',

            self::CashierBot, self::PosBot, self::OwnerBot => 'operator',

            self::FilamentAdmin, self::ReconcileJob,
            self::SystemBackfill => 'manual',
        };
    }
}
```

**Migration note:** existing `CashTransactionSource` values map 1:1. `FxSourceTrigger` (5 values describing *how the FX rate was pushed*) is a different axis — renamed to `FxRateSyncTrigger` and kept separate. Don't conflate the two.

### 3.5 `PaymentMethod` — instrument enum

Replaces every `'cash'`, `'card'`, `'transfer'`, `'octo'`, `'gyg'`, `'naqd'`, `'наличные'` literal.

```php
enum PaymentMethod: string
{
    case Cash             = 'cash';
    case Card             = 'card';
    case BankTransfer     = 'bank_transfer';
    case OctoOnline       = 'octo_online';
    case GygPrePaid       = 'gyg_pre_paid';
    case Beds24External   = 'beds24_external';  // arrived via Beds24 outside our bots
    case Internal         = 'internal';         // internal movement (drawer open/close, adjustment)
}
```

Display labels live on the enum (one `label()` method, localized via Laravel translation), never in stored data.

### 3.6 Reference linking — the polymorphic cleanup

Kill all `reference = "expense:{id}"` / `"reversal:expense:{id}"` / `"EX-{YmdHis}"` string patterns.

| Link | Replacement |
|---|---|
| Pair (exchange legs) | `parent_entry_id` FK |
| Reversal | `reverses_entry_id` FK |
| Expense ↔ ledger | `counterparty_type='internal'` + domain-level projection; expense becomes a projection row |
| External booking | `beds24_booking_id` + `external_reference` + `external_item_ref` (both) |
| Octo | `external_reference` = Octo transaction_id; dedup via `(source, idempotency_key)` unique |

### 3.7 Idempotency

Every external webhook / bot callback provides an idempotency key:

| Source | Key |
|---|---|
| Beds24 webhook | `beds24_item_id` (payment row id) or content hash fallback |
| Octo callback | `octo_transaction_id` |
| GYG import | `external_reference` (booking code) |
| Cashier bot | Telegram `callback_query_id` |
| POS bot | Terminal transaction id |

Stored in `ledger_entries.idempotency_key` with unique constraint `(source, idempotency_key)`. Attempts to insert a duplicate either **noop** (if identical) or **throw** (if content differs — requires manual review).

### 3.8 Reversibility

A reversal is expressed as a **new entry** with `reverses_entry_id` set. No `soft deletes`, no `status='reversed'` flag on originals.

```
original → ledger_entries(id=100, type=OperationalExpense, direction=out, amount=50)
reversal → ledger_entries(id=101, type=OperationalExpense, direction=in,  amount=50, reverses_entry_id=100)
```

Balance queries **sum with direction signs**; a reversed entry plus its reversal net to zero.

---

## 4. Application layer — Actions

### 4.1 `RecordLedgerEntry` — the single contract

Primary action. Every flow that wants to write a ledger entry goes through this one class.

```php
namespace App\Actions\Ledger;

use App\DTOs\Ledger\LedgerEntryInput;
use App\Models\LedgerEntry;
use Illuminate\Support\Facades\DB;

class RecordLedgerEntry
{
    public function execute(LedgerEntryInput $input): LedgerEntry
    {
        // 1. Validate — DTO is already type-safe; this catches business rules:
        //    - direction + type compatibility
        //    - currency is supported
        //    - amount > 0
        //    - shift is open if shift_id provided
        //    - counterparty exists if id provided
        $input->validate();

        // 2. Idempotency check (outside transaction — fast-path)
        if ($input->idempotencyKey !== null) {
            $existing = LedgerEntry::where('source', $input->source->value)
                ->where('idempotency_key', $input->idempotencyKey)
                ->first();
            if ($existing) {
                return $existing;   // noop — idempotent replay
            }
        }

        // 3. Write in DB transaction
        return DB::transaction(function () use ($input) {
            $entry = LedgerEntry::create([
                'ulid'                   => (string) Str::ulid(),
                'idempotency_key'        => $input->idempotencyKey,
                'occurred_at'            => $input->occurredAt,
                'recorded_at'            => now(),
                'entry_type'             => $input->entryType->value,
                'source'                 => $input->source->value,
                'trust_level'            => $input->source->trustLevel(),
                'direction'              => $input->direction->value,
                'amount'                 => $input->amount,
                'currency'               => $input->currency->value,
                'fx_rate'                => $input->fxRate,
                'fx_rate_date'           => $input->fxRateDate,
                'daily_exchange_rate_id' => $input->dailyExchangeRateId,
                'presentation_snapshot'  => $input->presentationSnapshot?->toArray(),
                'usd_equivalent'         => $input->usdEquivalent,
                'counterparty_type'      => $input->counterpartyType->value,
                'counterparty_id'        => $input->counterpartyId,
                'booking_inquiry_id'     => $input->bookingInquiryId,
                'beds24_booking_id'      => $input->beds24BookingId,
                'cashier_shift_id'       => $input->cashierShiftId,
                'cash_drawer_id'         => $input->cashDrawerId,
                'payment_method'         => $input->paymentMethod->value,
                'override_tier'          => $input->overrideTier?->value ?? 'none',
                'override_approval_id'   => $input->overrideApprovalId,
                'variance_pct'           => $input->variancePct,
                'parent_entry_id'        => $input->parentEntryId,
                'reverses_entry_id'      => $input->reversesEntryId,
                'external_reference'     => $input->externalReference,
                'external_item_ref'      => $input->externalItemRef,
                'created_by_user_id'     => $input->createdByUserId,
                'created_by_bot_slug'    => $input->createdByBotSlug,
                'notes'                  => $input->notes,
                'tags'                   => $input->tags,
                'data_quality'           => $input->dataQuality ?? 'ok',
            ]);

            // 4. Dispatch projection event (async) — projections re-materialize
            event(new LedgerEntryRecorded($entry));

            return $entry;
        });
    }
}
```

The DTO `LedgerEntryInput` is the **single input contract**. Every caller builds one of these. There is no other way into the ledger.

### 4.2 Specialized source-adapter Actions

Each adapter knows **one source**, translates source-specific data into a `LedgerEntryInput`, and calls `RecordLedgerEntry`. These replace the scattered `CashTransaction::create` sites.

| Adapter action | Replaces | Responsibility |
|---|---|---|
| `IngestBeds24Payment` | `Beds24WebhookController:693`, `Beds24PaymentSyncJob` | Parse Beds24 webhook payload → `LedgerEntryInput` with `source=Beds24Webhook`, `trust=authoritative`, dedup via `beds24_item_id` |
| `IngestOctoPayment` | `OctoCallbackController:63, 179` | Parse Octo callback → `LedgerEntryInput` with actual `paidSum` (not `price_quoted`), source=OctoCallback, idempotency_key=transaction_id |
| `IngestGygPrePaidBooking` | `GygInquiryWriter:132` | Pre-paid GYG booking → `source=GygImport`, `payment_method=GygPrePaid` |
| `RecordCashReceived` | `BotPaymentService` (canonical), `RecordTransactionAction` single-leg path | Cashier bot payment against a booking |
| `RecordCashExpense` | `CashierExpenseService` | Expense `(type=OperationalExpense, direction=out)` + creates `CashExpense` projection row via observer |
| `RecordCashDeposit` | `CashierBotController:1065` (confirmCashIn) | Starting-balance or mid-shift top-up |
| `RecordCurrencyExchange` | `CashierExchangeService` | Two-leg write inside one action (parent_entry_id links legs) |
| `RecordSupplierPayment` | Filament `TourCalendar:432` | `type=SupplierPaymentOut`, polymorphic counterparty |
| `ReverseLedgerEntry` | `OwnerBotController:141` | Reversal with explicit `reverses_entry_id` FK, not fuzzy match |
| `RecordReconciliationAdjustment` | (new) | When external truth diverges from internal, emit `ReconciliationAdjust` entry to close the gap |

**Key principle:** each adapter is **≤100 LOC**. Its only job is translation. Business rules (amount > 0, shift open, etc.) live in `RecordLedgerEntry`.

### 4.3 Caller surface — what actually calls these

| Caller | Was | Becomes |
|---|---|---|
| `Beds24WebhookController::handle` | 1,189 LOC, inserts direct | ≤100 LOC, calls `IngestBeds24Payment` |
| `OctoCallbackController` | controller inserts direct | ≤100 LOC, calls `IngestOctoPayment` |
| `CashierBotController` | 1,819 LOC, inserts direct | bot controller routes intents → calls actions |
| `TelegramPosController` | 1,492 LOC, inserts direct | same pattern |
| `OwnerBotController` | inserts direct | same |
| `Filament\TourCalendar::quickGuestPay` | Filament writes direct | calls `RecordCashReceived` or `IngestOctoPayment` (depending on `payment_method`) |
| `Filament\TourCalendar::quickPay` | Filament writes direct | calls `RecordSupplierPayment` |
| `Filament\BookingInquiryResource markPaid` | Filament writes direct | calls appropriate action |
| `Filament\CreateCashTransaction` | bespoke `CreateRecord` | removed; admin uses a `RecordManualAdjustment` page |

---

## 5. Read model — projections

### 5.1 Contract

All reports and balance lookups query **projections**, not `ledger_entries` directly. Projections are:

- **Materialized** into their own tables (MySQL does not have incrementally-updated materialized views, so we maintain them with an observer)
- **Rebuildable from scratch** by replaying the ledger — `php artisan ledger:rebuild-projections`
- **Never written to by business logic** — only by the `LedgerEntryRecorded` event listener

### 5.2 Primary projections

| Projection | Replaces | Driven by |
|---|---|---|
| `cash_drawer_balances` | ad-hoc SQL in `CashDashboard`, `DrawerBalanceWidget`, `getBal()` in controllers | `cash_drawer_id, currency, balance` updated on every entry with a drawer |
| `shift_balances` | `CashierShift.expected_end_saldo` column math | `(shift_id, currency) → balance` |
| `guest_payment_view` | `guest_payments` table | read-only view/table: one row per entry with `entry_type ∈ {AccommodationPaymentIn, TourPaymentIn, AccommodationRefund, TourRefund}` and `counterparty_type='guest'` |
| `supplier_payment_view` | `supplier_payments` table | `entry_type = SupplierPaymentOut` |
| `expense_view` | `cash_expenses`, `expenses` | `entry_type = OperationalExpense` |
| `booking_inquiry_payment_summary` | ad-hoc SQL in `BookingInquiryResource`, `GuestBalances` | per-inquiry aggregate: total paid, total refunded, outstanding, by currency |
| `daily_cash_flow` | widgets' ad-hoc SQL | per `(date, drawer, currency)` aggregated amounts |
| `reconciliation_ledger_view` | (new) | joins `booking_payment_reconciliations` + ledger sum per booking — shows drift between internal and Beds24 |

Projections live as tables for performance. A **rebuild** command recomputes from scratch, providing a ground-truth check.

### 5.3 Filament reads projections only

- `GuestBalances`, `SupplierBalances`, `CashDashboard`, `ExpenseReports`, `BookingsReport`, `Reports` page — all query projections.
- Widgets (`CashFlowChart`, `CashTodayStats`, `DrawerBalanceWidget`, `ExpenseChart`) — same.
- No widget or page sums `ledger_entries` directly. That's the projection's job.

### 5.4 Reporting layer — a single `LedgerReportService`

Replaces `AdvancedReportService`, the DailyRecap math, and every widget's inline SQL:

```
class LedgerReportService
{
    public function dailyCashFlow(Carbon $date, ?int $drawerId = null, ?Currency $currency = null): DailyCashFlow;
    public function guestBalance(int $bookingInquiryId): GuestBalance;
    public function supplierBalance(string $type, int $id): SupplierBalance;
    public function drawerBalance(int $drawerId): array; // per currency
    public function reconciliationDrift(Carbon $from, Carbon $to): array;
    public function monthlyFinancialSummary(Carbon $month): MonthlyReport;
}
```

One class, unambiguous inputs, typed outputs. Fed by projections. Filament calls this; so does the daily recap command.

---

## 6. Enforcement — how the rules stay enforced

### 6.1 Code-level enforcement

1. **`LedgerEntry` model has a protected constructor and `__callStatic` guards.** `LedgerEntry::create(...)` outside the `RecordLedgerEntry` action throws. Only the action has the key to the door.
2. **Same for `GuestPayment`, `SupplierPayment`, `CashExpense`, `Expense`** — once migrated to projections, their models become `class extends Model { protected $readOnly = true; }` with guard hooks.
3. **`CashTransaction` model stays** during coexistence (§7) but is marked `@deprecated` — a facade that reads from the projection built out of `ledger_entries` backfilled by `cash_transactions`.

### 6.2 Repository-wide rule

```
// In every relevant PHPDoc on actions:
/**
 * Writes to ledger_entries ONLY via RecordLedgerEntry action.
 * Direct LedgerEntry::create(), ::update(), ::delete() is forbidden
 * and enforced by CI via a grep-guard.
 */
```

### 6.3 CI enforcement (a simple, hard gate)

Add a CI script `bin/check-ledger-discipline.sh`:

```bash
# Fail build if any file creates LedgerEntry outside allowed paths.
ALLOWED="^app/Actions/Ledger/|^app/Services/Ledger/"

FORBIDDEN=$(
  grep -rnE 'LedgerEntry::(create|insert|firstOrCreate|updateOrCreate|query)' app/ --include='*.php' \
    | grep -vE "$ALLOWED"
)

if [ -n "$FORBIDDEN" ]; then
  echo "Direct LedgerEntry writes outside Actions/Ledger or Services/Ledger:"
  echo "$FORBIDDEN"
  exit 1
fi

# Same for money-projection models.
for model in GuestPayment SupplierPayment CashExpense Expense AgentPayment DriverPayment; do
  hits=$(grep -rnE "${model}::(create|update|delete|firstOrCreate|updateOrCreate)" app/ --include='*.php' \
           | grep -vE '^app/Projections/')
  if [ -n "$hits" ]; then
    echo "Direct write to projection model $model outside app/Projections/:"
    echo "$hits"
    exit 1
  fi
done
```

Runs on every PR.

### 6.4 Observability

- Every `RecordLedgerEntry` call emits a structured log line with `source`, `entry_type`, `amount`, `currency`, `idempotency_key`.
- `LedgerEntryRecorded` event is consumed by the projection updaters **synchronously** for single-row projections (balance) and **queued** for heavy aggregates (daily flow).
- `data_quality != 'ok'` rows surface in a Filament "Ledger Review" page for ops to examine.

---

## 7. Migration strategy — 4 ledgers → 1

### 7.1 Coexistence model

```
┌─────────────────────┐
│ Phase 5 start       │   only code writes going through RecordLedgerEntry
│                     │   legacy tables remain; backfill not yet run
├─────────────────────┤
│ Phase 6 coexistence │   RecordLedgerEntry writes to ledger_entries
│                     │   + a compat shim writes to legacy tables for readers
│                     │   projections are authoritative for new reports
├─────────────────────┤
│ Phase 7 backfill    │   historical cash_transactions + guest_payments +
│                     │   supplier_payments + cash_expenses backfilled into
│                     │   ledger_entries with source=SystemBackfill
├─────────────────────┤
│ Phase 8 cutover     │   legacy tables become read-only (triggers block writes)
│                     │   all Filament reads switch to projections
├─────────────────────┤
│ Phase 9 retirement  │   legacy tables dropped after N-week observation window
└─────────────────────┘
```

### 7.2 Mapping table — old → new

| Old row | New `ledger_entries` | Notes |
|---|---|---|
| `cash_transactions(type=in, category=sale)` | `AccommodationPaymentIn` or `TourPaymentIn` (by booking linkage) | Preserve `source_trigger` via mapping table |
| `cash_transactions(type=out, category=expense)` | `OperationalExpense` | Preserves `reference = "expense:{id}"` → `counterparty_type='internal'` + creates `expense_view` projection row |
| `cash_transactions(category=exchange)` pairs | 2 × `CurrencyExchangeLeg` with `parent_entry_id` | Pair recovered by shared `reference LIKE 'EX-%'` |
| `cash_transactions(type=in, reference LIKE 'reversal:%')` | `reverses_entry_id` backfilled via reference parsing | ❌ Where reference is missing / fuzzy, mark `data_quality='manual_review'` |
| `cash_transactions(type=in, category=deposit, source=manual_admin)` | `CashDeposit` | — |
| `guest_payments` (current) | `AccommodationPaymentIn` or `TourPaymentIn` by `booking_inquiry_id` / legacy `booking_id` | `payment_method` mapped from string to enum |
| `supplier_payments` | `SupplierPaymentOut` | polymorphic counterparty translated to enum |
| `cash_expenses` | `OperationalExpense` (joined with linked `cash_transactions` via `reference` to avoid duplicate) | — |
| `beginning_saldos` / `end_saldos` | `CashDrawerOpen` / `CashDrawerClose` | — |

### 7.3 Duplicate-migration resolution (P0 blocker)

Before any backfill, the 3 duplicate `Schema::create` migrations must be resolved:

- **`guest_payments`** — production runs on whichever migration ran first. Write a consolidation migration that:
  1. Introspects current schema (PHP migration runs `DB::select("SHOW COLUMNS")`)
  2. If v1 schema (`guest_id` + `booking_id`) — migrate data to v2 shape (`booking_inquiry_id` + refund semantics) *before* dropping the old table
  3. Delete the obsolete migration file and mark it in `MIGRATION_HISTORY.md`
- **`booking_fx_syncs`** — same approach; diff is small (24h apart, likely rename/re-type)
- **`fx_manager_approvals`** — same

No `php artisan migrate:fresh` survives today. Fix first.

### 7.4 Backfill plan

Dedicated command: `php artisan ledger:backfill {--from=} {--to=} {--dry-run}`.

- Iterates `cash_transactions` in `occurred_at` order, inserts corresponding `ledger_entries(source=SystemBackfill)` with **original `source_trigger` preserved in `tags`**
- Idempotent: `ledger_entries.external_reference = 'legacy:cash_transactions:{id}'`, unique
- Same for `guest_payments`, `supplier_payments`, `cash_expenses`
- Reversals: parse `reference LIKE 'reversal:expense:%'` → set `reverses_entry_id`. Where fuzzy match was used, mark `data_quality='manual_review'`
- Exchange legs: pair by shared `reference LIKE 'EX-%'` → set `parent_entry_id`
- After backfill, **reconcile count**: `SELECT COUNT(*) FROM ledger_entries WHERE source=SystemBackfill` must match combined legacy tables

### 7.5 Rollout sequencing (maps to Phase 5 tickets)

| Ticket | Scope | Risk | Dependency |
|---|---|---|---|
| L-001 | P0: Resolve 3 duplicate `Schema::create` migrations | 🔴 data integrity | — |
| L-002 | P0: Collapse `BotPaymentService` duplicates; pick canonical; delete `Fx/` copy | 🔴 ambiguous wiring | — |
| L-003 | Create `ledger_entries` migration + model (read-only protections) | 🟢 additive | L-001 |
| L-004 | Implement `RecordLedgerEntry` action + `LedgerEntryInput` DTO + enums | 🟢 additive | L-003 |
| L-005 | Implement `LedgerEntryRecorded` event + projection updater skeleton | 🟢 additive | L-004 |
| L-006 | Implement adapters (IngestBeds24Payment, IngestOctoPayment, IngestGygPrePaidBooking) | 🟡 replaces controllers' direct inserts | L-004 |
| L-007 | Migrate `Beds24WebhookController` to call adapter; keep legacy insert behind flag | 🟡 | L-006 |
| L-008 | Migrate `OctoCallbackController` — fix amount drift in same commit | 🟡 | L-006 |
| L-009 | Migrate `CashierBotController`, `TelegramPosController`, `OwnerBotController` to adapters | 🟡 | L-004 |
| L-010 | Migrate Filament pages (`TourCalendar`, `BookingInquiryResource`) to adapters | 🟡 | L-004 |
| L-011 | Build projections for `cash_drawer_balances`, `shift_balances`, `daily_cash_flow` | 🟢 | L-005 |
| L-012 | Build `guest_payment_view`, `supplier_payment_view`, `expense_view` projections | 🟢 | L-005 |
| L-013 | Implement `LedgerReportService` — port `AdvancedReportService` and DailyRecap math onto projections | 🟡 | L-011, L-012 |
| L-014 | Migrate Filament pages & widgets to `LedgerReportService` | 🟡 | L-013 |
| L-015 | Backfill command (dry-run first, then production) | 🟡 one-time migration | L-004, L-003 |
| L-016 | Switch `ReconciliationService` to use ledger projections + absorb `Fx/WebhookReconciliationService` | 🟡 | L-011 |
| L-017 | Enforce CI guards (`bin/check-ledger-discipline.sh`) | 🟢 | all above |
| L-018 | Freeze legacy tables (read-only trigger) + observation window | 🟡 | L-015 |
| L-019 | Drop legacy tables after N weeks | 🔴 destructive | L-018 + sign-off |

### 7.6 Cutover criteria (all must be green)

- Zero direct `LedgerEntry::create` outside `app/Actions/Ledger/` — CI guard enforces
- Zero direct writes to `GuestPayment`, `SupplierPayment`, `CashExpense`, `Expense` outside `app/Projections/`
- `ledger:rebuild-projections --verify` completes with zero drift between projections and ledger sums for 7 consecutive days
- `ReconciliationService` runs green for 7 consecutive days post-cutover
- Daily cash report matches pre-cutover baseline within ±0.01 for 7 consecutive days

Only then do we freeze + drop legacy tables.

---

## 8. What this changes in practice

### 8.1 Controllers shrink dramatically

- `Beds24WebhookController` 1,189 LOC → ≤150 LOC (validation, dispatch to `IngestBeds24Payment`, alert logic extracted)
- `CashierBotController` 1,819 LOC → ≤400 LOC (intent routing only; business logic in actions + bot-session service)
- `TelegramPosController` 1,492 LOC → ≤400 LOC
- `OctoCallbackController` → ≤100 LOC

### 8.2 Filament pages read only

No `Model::create(...)` calls in `app/Filament/` after migration. Every write button calls an action.

### 8.3 The ledger becomes queryable truth

- "Show me every money event involving booking X" → single SQL on `ledger_entries`
- "What did the shift look like at 18:00?" → query `ledger_entries WHERE cashier_shift_id = X AND occurred_at <= '18:00'`
- "Reconstruct balances as of yesterday" → `ledger:rebuild-projections --as-of=yesterday`

These are not possible today.

### 8.4 Reports are reproducible

Same inputs → same outputs. Every Filament page and widget yields identical numbers because they all go through `LedgerReportService` → projections → ledger.

---

## 9. Deferred — Task core (Phase 4.5)

The **operational task core** (housekeeping / kitchen / maintenance / room issues) is not designed here. It requires its own deep-dive.

### 9.1 What Phase 4.5 must answer

- How do `RoomCleaning`, `RoomRepair`, `RoomIssue` unify into a single `Task` aggregate?
- What is the task lifecycle (`created → assigned → in_progress → blocked → completed → verified`)?
- How do bot controllers (`HousekeepingBotController`, `KitchenBotController`) become adapters?
- How do tasks produce ledger entries when they incur costs (maintenance expense, materials)?

### 9.2 What Phase 4.5 reuses from this phase

- The same 3-layer contract
- The same adapter-action pattern
- The same absolute rule **for money** — even when a task causes a money event, it goes through `RecordLedgerEntry`

---

## 10. Phase 4 complete — next

Phase 4 produces:
- ✅ Ledger model design (`ledger_entries` schema)
- ✅ Single-contract action (`RecordLedgerEntry`) + source adapters
- ✅ Projection / read-model strategy
- ✅ Enforcement rules (code-level + CI)
- ✅ Migration strategy (coexistence → backfill → cutover)

Phase 5 (`REFACTOR_PLAN.md`) converts L-001 through L-019 into prioritized tickets with estimates, risk, and test plans. Phase 4.5 (task core) can run in parallel.

---

## Open questions for user sign-off before Phase 5

1. **Currency storage precision** — spec uses `DECIMAL(14,2)`. UZS amounts can run in the tens of millions; should be fine, but confirm.
2. **Retention** — ledger is append-only. Data volume after 5 years? Rough estimate: 50k–200k rows/year × 5 = up to 1M rows. Negligible for MySQL. No archival planned. Confirm acceptable.
3. **Projection refresh mode** — propose **observer-driven synchronous** for row-level balances, **queued** for aggregates. Alternative: pure event-sourced queue for everything (simpler; higher lag). Pick.
4. **`cash_transactions` model facade** — proposal: during coexistence, keep the class as a read-only view over ledger projections. Alternative: delete it and accept the churn. Pick.
5. **Octo legacy vs new path** — the legacy `Booking` + `GuestPayment(guest_id, booking_id)` schema is still callable. Decision: kill the legacy path entirely during L-008, force all flows through `BookingInquiry`. Confirm.
6. **FX approval UX** — the override/approval flow is complex. Is preserving exact UX important, or can it be simplified alongside this refactor?
7. **"Manual review" surfacing** — `data_quality='manual_review'` rows need a Filament page. Worth spec'ing, or wait until data lands?
8. **Tourfirm panel scope** — does it touch money? If yes, same rules apply. If no, out-of-scope for this phase.
