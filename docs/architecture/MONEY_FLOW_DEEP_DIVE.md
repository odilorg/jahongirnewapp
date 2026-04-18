# MONEY FLOW DEEP DIVE — Jahongir Hotel Operations System

**Phase:** 3.5 · **Generated:** 2026-04-18 · **Commit baseline:** `ec573cf`
**Inputs:** `INVENTORY.md`, `ROUTES_AND_ENTRYPOINTS.md`, `DOMAINS.md`, direct code reading of every mutation site.

> This document is the gate between analysis and design. Phase 4 (`TARGET_ARCHITECTURE.md`) is built on its conclusions.

---

## 0. Executive summary

Five findings shape everything that follows.

1. **`cash_transactions` is an implicit ledger that already exists but is not authoritative.** Per `app/Services/ReconciliationService.php:16–17` (verbatim): *"Uses Beds24 invoice_balance as source of truth for payment status, NOT CashTransaction records (which only track cash/naqd payments)."* The system itself does not treat its internal table as the truth.
2. **13 distinct mutation sites** write money state. Only 2 go through `RecordTransactionAction`. The rest are scattered across controllers, Filament pages, services — and two duplicate `BotPaymentService` classes.
3. **`source_trigger` is the closest thing to an event type.** Values are mixed — some coded as strings (`'cashier_bot'`, `'manual_admin'`, `'beds24_external'`), some as enum cases (`CashTransactionSource::CashierBot`). The taxonomy is incoherent.
4. **`GuestPayment` and `SupplierPayment` are orphan tables.** They are created from Filament pages and controllers — never linked to a `CashTransaction` and never visible to reconciliation. These are two parallel, unlinked ledgers.
5. **Non-cash payments are invisible to the cash ledger.** Only flows originating from the Telegram cashier/POS bots create `CashTransaction` rows. Card, online (Octo), GYG-prepaid, OTA-received payments do not. This is why reconciliation cannot use `cash_transactions` as truth — it doesn't contain all the money.

---

## 1. Scope of analysis

All money mutation sites identified in `DOMAINS.md §B3` are examined individually below. Each entry follows this structure:

- **Entrypoint** — where the flow starts
- **Validation path** — what is checked before the write
- **Services / Actions called** — the application-layer chain
- **Models touched** — reads and writes
- **DB writes** — the concrete rows inserted / updated
- **Calculations performed** — any math inside the flow
- **Side effects** — notifications, logs, external calls
- **Mutation chain** — `Input → Validation → Transformation → Write → Post-processing`
- **Critical analysis** — inconsistency / duplication / overwrite / replayability risks
- **Reproducibility test** — can this flow be replayed deterministically from stored data? ✅ / 🟡 / ❌
- **Classification** — SAFE · RISKY · BROKEN

---

## 2. Flow-by-flow analysis

### 2.1 🟢 SAFE — `RecordTransactionAction` (the correct reference)

**Entrypoint:** `app/Actions/RecordTransactionAction.php::execute($shift, $user, $data)`
**Currently called by:** only two Filament-adjacent flows (see `DOMAINS.md §B4`). Should be the universal contract.

| Aspect | Detail |
|---|---|
| Validation | Laravel `Validator` inline: type, amount, currency, optional out_currency/amount, category, reference, notes |
| Services | — (is itself an Action) |
| Models touched | `CashierShift` (read + update), `CashTransaction` (create, 1 or 2 rows) |
| DB writes | 1× `cash_transactions` (or 2× for `in_out` exchange), 1× `cashier_shifts.expected_end_saldo` |
| Calculations | `$shift->calculateExpectedEndSaldo()` on the shift — side-effect of the write |
| Side effects | Wraps everything in `DB::transaction`. Re-checks `$shift->isOpen()` inside the transaction |

**Mutation chain:**
```
array $data  →  Validator  →  type-guard (in_out requires out_*)
                           →  DB::transaction
                                →  shift open check
                                →  CashTransaction::create (leg 1)
                                →  CashTransaction::create (leg 2, if in_out)
                                →  shift->update(expected_end_saldo)
                           →  return transaction
```

**Analysis:**
- ✅ Atomic write in a DB transaction
- ✅ Validates shift is open at write time (not at request time)
- 🟡 Calculates expected_end_saldo **inside** the action — that's a projection, not an event. Should be derivable.
- 🟡 Does NOT set `source_trigger` — every transaction from this action has NULL source
- 🟡 Does NOT set FX / presentation snapshot columns even when the currency is not the shift's base
- 🟡 Does NOT set `beds24_booking_id` — cannot be tied to a booking
- 🟡 No idempotency key — same call twice creates two rows

**Reproducibility:** 🟡 partial — rows can be re-derived from inputs, but without `source_trigger` the origin of the row is unknown after the fact.

**Classification: 🟢 SAFE pattern, but incomplete as ledger contract.**

---

### 2.2 🟡 RISKY — `CashierExchangeService` (currency exchange)

**Entrypoint:** `app/Services/CashierExchangeService.php::recordExchange($shiftId, $data, $createdBy, $callbackId)`
**Caller:** `CashierBotController` (bot "currency exchange" flow)

| Aspect | Detail |
|---|---|
| Validation | **None** inside the service — trusts controller's array data |
| DB writes | 2× `cash_transactions` (one `in`, one `out`) with `category = 'exchange'` and shared `reference = EX-{YmdHis}` |
| Calculations | — none; amounts come in from caller |
| Side effects | Telegram callback idempotency via raw SQL on `telegram_processed_callbacks` |

**Mutation chain:**
```
$shiftId + $exchangeData (in_amount, in_currency, out_amount, out_currency)
  →  DB::transaction
       →  lockForUpdate on CashierShift
       →  isOpen() re-check
       →  CashTransaction::create (leg IN, with related_currency/related_amount)
       →  CashTransaction::create (leg OUT, with related_currency/related_amount)
       →  telegram_processed_callbacks.update (raw DB facade)
  →  return $ref
```

**Analysis:**
- ✅ Atomic, with row-level lock — safer than action
- ✅ `related_currency`/`related_amount` linked on both sides — an exchange can be reconstructed
- ✅ Shared `reference` string groups the two legs
- 🟡 `source_trigger = 'cashier_bot'` (string) — but `RecordTransactionAction` does not set this. **Two writers, two source-trigger conventions.**
- 🔴 No validation on currency codes or sign — negative amounts would pass through
- 🔴 No linkage to a `BookingFxSync` or `DailyExchangeRate` — the rate used is not stored anywhere
- 🔴 Raw DB facade used for callback idempotency — bypasses Eloquent, no audit

**Reproducibility:** 🟡 the two legs can be paired via `reference`; the rate used is lost.

**Classification: 🟡 RISKY — pair is reconstructable but FX rate is not captured.**

---

### 2.3 🟡 RISKY — `CashierExpenseService` (expense with linked cash out)

**Entrypoint:** `app/Services/CashierExpenseService.php::recordExpense($shiftId, $data, $createdBy, $callbackId)`
**Caller:** `CashierBotController` expense submission

| Aspect | Detail |
|---|---|
| Validation | **None** inside the service |
| DB writes | 1× `cash_expenses`, 1× `cash_transactions` (`type=out`, `category=expense`, `reference=expense:{id}`) |
| Side effects | Same raw-SQL callback flag |

**Mutation chain:**
```
$shiftId + expenseData (cat_id, cat_name, amount, currency, desc, needs_approval)
  →  DB::transaction
       →  lockForUpdate shift + isOpen re-check
       →  CashExpense::create
       →  CashTransaction::create (type=out, reference=expense:{id})  ← links via string ref
       →  telegram_processed_callbacks.update
```

**Analysis:**
- ✅ Both rows in one transaction — expense and ledger side are atomic
- ✅ `reference = "expense:{$expense->id}"` acts as a soft FK
- 🔴 **No actual foreign key** between `cash_expenses.id` and `cash_transactions` — relies on string parsing. `OwnerBotController::reverseExpenseTransaction:130` does exactly this lookup **and falls back to fuzzy match** (shift + type + amount + currency + notes).
- 🔴 If an approver rejects the expense, `OwnerBotController:141` creates a **compensating `in` transaction** instead of annotating the original — two unlinked rows model a reversal.

**Reproducibility:** 🟡 works when reference string survives; fuzzy fallback is not deterministic.

**Classification: 🟡 RISKY — soft FK + string reference is fragile for a financial system.**

---

### 2.4 🔴 BROKEN — `app/Services/BotPaymentService.php` (top-level) — booking payment via Cashier Bot

**Entrypoint:** `BotPaymentService::record(...)` (line 145 — creates at `:179`)
**Caller:** `CashierBotController` "pay booking" flow

| Aspect | Detail |
|---|---|
| Validation | `BookingNotPayableException`, duplicate-payment guard, `warnIfAlreadyFullyPaid` |
| Services called | Reads `BookingFxSync::find($syncId)`; consumes a `FxManagerApproval` row; calls `$this->resolveUsdEquivalent(...)` |
| DB writes | 1× `cash_transactions` with 20+ columns: **presentation snapshot, group audit, override tier, approval chain, sync FK, rate FK, USD equivalent** |
| Side effects | Locks `Beds24Booking` `lockForUpdate`; may update/lock `fx_manager_approvals`; logging |

**Mutation chain:**
```
payBookingData (presentation DTO)
  →  exception guards
  →  warnIfAlreadyFullyPaid()
  →  DB::transaction
       →  Beds24Booking::lockForUpdate
       →  isPayable() check
       →  guardAgainstDuplicatePayment()
       →  BookingFxSync::find (rate snapshot)
       →  resolveUsdEquivalent()
       →  CashTransaction::create (~25 cols)
       →  FxManagerApproval consume (if override)
       →  Beds24 push queued
```

**Analysis:**
- ✅ Serious engineering — locks, duplicate guards, stale-rate checks
- 🔴🔴 **This class is duplicated** at `app/Services/Fx/BotPaymentService.php:149` — see 2.5 below
- 🔴 Different `source_trigger` from other writers — uses enum `CashTransactionSource::CashierBot->value`
- 🔴 Writes to presentation-snapshot columns (`amount_presented_*`) — but those same columns are NULL for all other writers
- 🔴 The "presentation snapshot" concept is this service's invention and is not respected anywhere else
- 🟡 Idempotency via duplicate-payment guard — not via an explicit event-id column

**Reproducibility:** 🟢 this flow is the closest to reproducible — it stores FX snapshot, rate FKs, override chain, group audit.

**Classification: 🔴 BROKEN — not because of its logic, but because a second, diverged copy of this class exists. Live call site is unclear.**

---

### 2.5 🔴 BROKEN — `app/Services/Fx/BotPaymentService.php` (duplicate)

**Entrypoint:** `Fx/BotPaymentService::record(...)` — creates at `:149`

| Aspect | Detail |
|---|---|
| Validation | Duplicate-payment guard by `source_trigger = CashierBot`, staleness check on `presentation`, override policy evaluation, manager approval check |
| DB writes | 1× `cash_transactions` with similar columns to 2.4 but **different schema calls** (`OverrideTier::Manager`, different DTO shape, different presentation DTO) |

**Analysis:**
- 🔴 **Divergent from top-level class.** Key differences observed:
  - Top-level uses `$data->presentation->isStale()` via a different presentation DTO
  - `Fx/` version calls `$this->overridePolicy->evaluate(...)` inline; top-level assumes pre-evaluated via external flow
  - Top-level has explicit "group audit" comment; `Fx/` version does not
  - Both set `source_trigger = CashTransactionSource::CashierBot->value` — so writes from both look identical downstream
- 🔴 Either version could be the live one depending on which is imported by `TelegramPosController` vs `CashierBotController` vs their sub-services.
- 🔴 **Split-brain risk:** bugs fixed in one copy will not be fixed in the other.

**Reproducibility:** 🟢 per-row, but **not from a single code path** — two code paths produce structurally similar rows by different means.

**Classification: 🔴 BROKEN — duplicate = ambiguous ledger behaviour.**

---

### 2.6 🔴 BROKEN — `CashierBotController@confirmCashIn` (cash-drop / starting balance)

**Entrypoint:** `app/Http/Controllers/CashierBotController.php:1050` → creates at `:1065`

| Aspect | Detail |
|---|---|
| Validation | Only role check (`super_admin`/`admin`/`manager`) + positive-amount check |
| DB writes | 1× `cash_transactions`: `type=in, category=deposit, source_trigger='manual_admin'` |
| Notes | `"Внесение наличных (начальный баланс)"` |

**Analysis:**
- 🔴 **Controller writes money directly.** No service, no action, no transaction wrapper.
- 🔴 `source_trigger = 'manual_admin'` (string literal) — not the enum. **Inconsistency with 2.4/2.5 which use the enum value.** A report filtering by enum will miss this row.
- 🔴 No FX snapshot, no linkage to `BookingFxSync` — but rows are in UZS/USD so rate can drift without being captured
- 🟡 Idempotency: relies on user pressing button once — no callback check

**Reproducibility:** 🟡 rows exist but without rate or booking context.

**Classification: 🔴 BROKEN — presentation layer mutates ledger; source taxonomy is incoherent.**

---

### 2.7 🔴 BROKEN — `OwnerBotController@reverseExpenseTransaction` (expense rejection → compensating entry)

**Entrypoint:** `app/Http/Controllers/OwnerBotController.php:125` → creates at `:141`

| Aspect | Detail |
|---|---|
| DB writes | 1× `cash_transactions` with `type=in`, `reference="reversal:expense:{id}"`, no `created_by` |

**Analysis:**
- 🔴 **Controller creates compensating ledger entry** instead of invoking a reversal action
- 🔴 **Fuzzy fallback** to find the original: if `reference` lookup fails, matches by `shift_id + type=out + amount + currency + notes + latest()`. Two expenses with same amount/notes would be indistinguishable.
- 🔴 Compensating entry has **no FK** to the original `cash_transactions.id` — the relationship is string-based (`reversal:expense:{id}`), and even then it references the expense, not the original transaction.
- 🔴 `created_by` is not set — the reversal is authorless in the ledger.

**Reproducibility:** ❌ If the reference string is stripped or the amount/notes change, the reversal cannot be unambiguously paired with the original.

**Classification: 🔴 BROKEN — reversal semantics without referential integrity.**

---

### 2.8 🔴 BROKEN — `Beds24WebhookController@handle` → CashTransaction (external payment ingestion)

**Entrypoint:** `app/Http/Controllers/Beds24WebhookController.php` → creates at `:693`

| Aspect | Detail |
|---|---|
| Validation | 3-level dedup: `(beds24_item_id, source_trigger)`, `(reference, source_trigger)`, content-hash (`booking_id + source + amount + notes`) |
| DB writes | 1× `cash_transactions` with `source_trigger = 'beds24_external'`, `cashier_shift_id = active shift if any (null otherwise)`, `beds24_payment_ref = "b24_item_{id}"` |
| Side effects | If method is cash-like, fires `alertViolation()` Telegram message to owner |

**Analysis:**
- ✅ Dedup logic is thoughtful — 3 levels
- 🔴 **Controller writes money directly.** 1,189-LOC controller with this inside it.
- 🔴 `cashier_shift_id` is set to "whatever active shift exists" or NULL — a payment belonging to nobody's shift is a reporting outlier
- 🔴 `source_trigger = 'beds24_external'` — yet another string convention
- 🔴 Endpoint has **no authentication** (explicitly documented)
- 🔴 Creates rows for card/transfer payments that the rest of the system does not consider part of the cash ledger — yet it writes to `cash_transactions`

**Reproducibility:** 🟡 dedup keys are reasonable; but if Beds24 changes its item-id, or the same item arrives with different method, dedup fails.

**Classification: 🔴 BROKEN — controller owns external ingestion; shift attachment is best-effort.**

---

### 2.9 🔴 BROKEN — `OctoCallbackController@handleBookingCallback` (legacy Booking)

**Entrypoint:** `OctoCallbackController:43` → creates at `:63`

| Aspect | Detail |
|---|---|
| Validation | Regex-parses transaction_id for booking number; 404 if missing |
| DB writes | 1× `Booking.payment_status = 'paid'` (.save), 1× `GuestPayment::create` with `guest_id`, `booking_id`, `payment_method = 'card'` |

**Analysis:**
- 🔴 Controller mutates `Booking` **and** creates `GuestPayment` in one method — no service
- 🔴 Uses the **legacy `guest_payments` schema** (`guest_id` + `booking_id`) — which is the older migration. The Apr 2026 migration changed the schema to `booking_inquiry_id`. This path either (a) silently fails if the new schema wins, or (b) uses an orphan schema.
- 🔴 No idempotency — a duplicate Octo webhook would create a second `GuestPayment`.
- 🔴 No linked `CashTransaction` — card payment never reaches the cash ledger.

**Reproducibility:** ❌ without the callback being replayed, there is no deterministic source for the row.

**Classification: 🔴 BROKEN — plus sits on a schema that is being replaced.**

---

### 2.10 🟡 RISKY — `OctoCallbackController@handleInquiryCallback` (new BookingInquiry path)

**Entrypoint:** `OctoCallbackController:108` → creates at `:179`

| Aspect | Detail |
|---|---|
| Validation | Two idempotency guards: (a) if `inquiry.paid_at` already set → skip; (b) if inquiry status is cancelled/spam → store fields and raise red-flag (no silent revival) |
| DB writes | 1× `BookingInquiry.update(status, payment_method, confirmed_at)`, 1× `GuestPayment::create` with `booking_inquiry_id`, `payment_method='octo'`, `reference=transaction_id` |
| Side effects | `BookingInquiryNotifier::notifyPaid` |

**Analysis:**
- ✅ Idempotency guards are genuine
- ✅ Terminal-status guard is proper
- 🔴 **No linked `CashTransaction`.** Card/online payment is invisible to the cash ledger and to reconciliation.
- 🔴 Amount is taken from `$inquiry->price_quoted` — **not** from the actual `$paidSum` reported by Octo. If a guest pays a different amount (partial, overpaid), the `GuestPayment.amount` is still `price_quoted`. The reported `$paidSum` is only logged.
- 🔴 Controller writes to inquiry and guest_payments — no service involved.

**Reproducibility:** 🟡 guard chain is good; the divergence between `GuestPayment.amount` and Octo `paidSum` is a silent data issue.

**Classification: 🟡 RISKY — best-practised flow in the controller layer, but amount does not reflect what was actually paid.**

---

### 2.11 🔴 BROKEN — Filament `TourCalendar::quickGuestPay` (slide-over "record payment")

**Entrypoint:** `app/Filament/Pages/TourCalendar.php:151` → creates at `:160`

| Aspect | Detail |
|---|---|
| Validation | Inquiry exists + amount is set |
| DB writes | 1× `GuestPayment::create` with `booking_inquiry_id`, `amount = $this->guestPayAmount`, `payment_type = (amount >= price_quoted) ? 'full' : 'balance'`, `payment_method = $this->guestPayMethod`, `recorded_by_user_id = auth()->id()`, `status='recorded'` |

**Analysis:**
- 🔴 **No service, no action.** Filament page writes money directly.
- 🔴 **No `CashTransaction` created** — even for `payment_method = 'cash'`. A cashier using TourCalendar to log a cash payment creates a `GuestPayment` but no ledger row. The cashier bot, however, creates a `CashTransaction`. **Two flows, one concept, incoherent behaviour.**
- 🔴 No shift linkage even when it's a cash payment
- 🔴 No FX snapshot when non-USD
- 🔴 No idempotency

**Reproducibility:** ❌ the row is a final state with no linkage to an upstream event.

**Classification: 🔴 BROKEN — primary shadow-ledger risk.**

---

### 2.12 🔴 BROKEN — Filament `TourCalendar::quickPay` (supplier payment)

**Entrypoint:** `TourCalendar.php:423` → creates at `:432`

| Aspect | Detail |
|---|---|
| DB writes | 1× `SupplierPayment::create` with polymorphic `supplier_type` + `supplier_id`, `booking_inquiry_id`, `amount`, `currency='USD'`, `payment_method`, `status='recorded'` |

**Analysis:**
- 🔴 Same problems as 2.11: no service, no action, no linked `CashTransaction`, no shift, no FX snapshot
- 🔴 **Zero service callers** for `SupplierPayment` model — this page is the only writer
- 🔴 Polymorphic `(supplier_type, supplier_id)` **with no shared interface/contract** — no guarantee the referenced row exists

**Classification: 🔴 BROKEN — supplier payment has no presence in the cash ledger at all.**

---

### 2.13 🔴 BROKEN — Filament `BookingInquiryResource` `markPaid` action

**Entrypoint:** `app/Filament/Resources/BookingInquiryResource.php` → creates at `:1376`

| Aspect | Detail |
|---|---|
| Validation | amount > 0 (inline) |
| DB writes | 1× `BookingInquiry.update(status, confirmed_at, price_quoted?, currency)`, 1× `GuestPayment::create` |

**Analysis:**
- 🔴 Filament resource action contains business logic
- 🔴 No `CashTransaction`
- 🔴 Conditional: if `payment_method === 'online'` then `'octo'`, else literal value — **silently rewrites the payment_method taxonomy**

**Classification: 🔴 BROKEN.**

---

### 2.14 🟡 RISKY — Filament `CreateCashTransaction` page

**Entrypoint:** `app/Filament/Resources/CashTransactionResource/Pages/CreateCashTransaction.php`

| Aspect | Detail |
|---|---|
| Validation | Cashier role → shift-open check, else redirect to start-shift |
| DB writes | Handled by base `CreateRecord`; `afterCreate()` creates a second row for `in_out` complex transactions |

**Analysis:**
- ✅ Enforces "open shift" for cashier role
- 🟡 Uses Filament's stock `CreateRecord` — bypasses `RecordTransactionAction` entirely. **The action exists, but the Filament admin creation path doesn't use it.**
- 🔴 `afterCreate()` duplicates the complex-transaction logic already in `RecordTransactionAction::execute` — two implementations of the same semantic.
- 🔴 Non-cashier users (admin, manager) are NOT required to have an open shift — they can create transactions without any shift context.

**Reproducibility:** 🟡 the row is deterministic but `RecordTransactionAction`'s projection side-effect (`calculateExpectedEndSaldo`) is skipped.

**Classification: 🟡 RISKY — third implementation of the same "create transaction" use-case.**

---

### 2.15 🟡 RISKY — `Gyg/GygInquiryWriter` (pre-paid GYG booking → GuestPayment)

**Entrypoint:** `app/Services/Gyg/GygInquiryWriter.php` → creates at `:132`

| Aspect | Detail |
|---|---|
| Validation | `if ((float) $inquiry->price_quoted > 0)` — only create payment if priced |
| DB writes | 1× `BookingInquiry::save()`, 1× `$email->update(processing_status = 'applied')`, 1× `GuestPayment::create(payment_method='gyg', reference=external_reference)` |

**Analysis:**
- ✅ Lives in a service (not controller / Filament)
- ✅ Has reference linkage (`external_reference`)
- ✅ Wrapped by outer `DB::transaction` in the caller
- 🔴 Same omission: **no linked `CashTransaction`.** GYG pre-paid revenue never hits the cash ledger.
- 🔴 `amount = price_quoted` — not the GYG-reported amount (same issue as 2.10)

**Classification: 🟡 RISKY — well-structured but still an orphan `GuestPayment`.**

---

### 2.16 🟡 RISKY — `ReconciliationService::reconcile` (the truth-finder)

**Entrypoint:** scheduled `cash:reconcile` daily 21:00 → `ReconciliationService::reconcile($from, $to)`

| Aspect | Detail |
|---|---|
| DB writes | `BookingPaymentReconciliation::updateOrCreate(['beds24_booking_id' => ...], [...])` per booking |
| Reads | `Beds24Booking.invoice_balance` **as truth** (explicit code comment: NOT `CashTransaction` sum) |

**Analysis:**
- ✅ Service-owned, proper DB writes
- 🔴🔴 **`ReconciliationService.php:17` declares Beds24 invoice_balance is the source of truth** — the internal ledger is **not authoritative by design**. The system has accepted that truth lives in an external system.
- 🔴 Second reconciliation service exists (`Fx/WebhookReconciliationService.php`) — two reconciliation lenses with no consolidation
- 🔴 Does not reconcile `GuestPayment`, `SupplierPayment`, `CashExpense`, `CashTransaction`, or the FX chain — only `Beds24Booking.invoice_balance` vs expected

**Reproducibility:** 🟢 deterministic given Beds24 snapshot — but that's a window onto an external truth, not an internal ledger reconciliation.

**Classification: 🟡 RISKY — not because the service is wrong, but because it confirms the internal ledger is not authoritative.**

---

### 2.17 Minor entrypoints (scheduled FX + reminders)

Enumerated without deep dive — these are writers that do not create `CashTransaction` directly but are part of the money picture.

| Entrypoint | Writes | Classification |
|---|---|---|
| `fx:push-payment-options` cron | `BookingFxSync`, `DailyExchangeRate` | 🟢 FX snapshot is explicit and dated |
| `fx:repair-missing`, `fx:repair-stuck-syncs` | `BookingFxSync` (update) | 🟡 mutates prior snapshots |
| `fx:expire-approvals` | `FxManagerApproval.status = Expired` | 🟢 explicit lifecycle |
| `fx:nightly-report` | read-only | 🟢 |
| `inquiry:send-payment-reminders` | `BookingInquiry.payment_reminder_sent_at` | 🟢 non-financial column |
| `Beds24PaymentSyncJob` | `Beds24PaymentSync`, `CashTransaction` (via Fx service) | 🟡 inherits 2.4/2.5 duplicate-class issue |

---

## 3. Cross-cutting observations

### 3.1 Taxonomies that drift

| Concept | Values in the wild | Problem |
|---|---|---|
| `source_trigger` | `'cashier_bot'`, `'manual_admin'`, `'beds24_external'`, `CashTransactionSource::CashierBot->value`, `'cashier_bot'` (duplicated in enum) | Mix of string literals and enum values — a report filtering by enum will miss string rows |
| `payment_method` | `'cash'`, `'card'`, `'transfer'`, `'octo'`, `'gyg'`, `'naqd'` (Uzbek), `'naличные'` (Russian) | Multi-language, no normalization |
| `GuestPayment.status` | `'recorded'`, `'paid'` (legacy) | Schema split (see migration dupes) |
| `BookingInquiry.status` | Constants on the model; observer promotes `paid_at` | ✅ typed, but logic spread across observer + controller + Filament |

### 3.2 "Same thing, N implementations"

| Use case | Implementations |
|---|---|
| Create a `CashTransaction` | `RecordTransactionAction`, `CashierExpenseService`, `CashierExchangeService`, `BotPaymentService` (top), `Fx/BotPaymentService`, `Beds24WebhookController`, `OwnerBotController`, `CashierBotController`, Filament `CreateCashTransaction` page |
| Create a `GuestPayment` | `OctoCallbackController` (legacy), `OctoCallbackController` (new), `TourCalendar`, `BookingInquiryResource` action, `GygInquiryWriter` |
| Reverse a financial event | `OwnerBotController::reverseExpenseTransaction` (compensating row) — only implementation, only for expenses |
| Reconcile truth | `ReconciliationService` (Beds24-anchored), `Fx/WebhookReconciliationService` |

### 3.3 FKs that aren't FKs

Several cross-table links are **string `reference` fields** not foreign keys:
- `cash_transactions.reference = "expense:{id}"`
- `cash_transactions.reference = "reversal:expense:{id}"`
- `cash_transactions.reference = "EX-{YmdHis}"` (exchange pair)
- `cash_transactions.reference = "Beds24 #{booking_id}"`

**Consequence:** cascade deletes and JOIN-based reports are impossible; fuzzy-match fallbacks exist (2.7) that can misattribute.

### 3.4 The "amount paid vs amount recorded" gap

Three flows record `GuestPayment.amount = inquiry.price_quoted` **rather than** the actual reported sum from the payment source:
- Octo new inquiry path (2.10) — ignores `$paidSum`
- Filament `BookingInquiryResource markPaid` (2.13)
- GYG pre-paid (2.15)

**Implication:** if a guest pays a different amount (refund, partial, overpayment, currency-converted), the `GuestPayment.amount` lies about what happened.

### 3.5 Two parallel ledgers

| Ledger | Writers | Readers |
|---|---|---|
| **Cash ledger** (`cash_transactions`) | 9 call sites | `AdvancedReportService`, cash dashboards, widgets |
| **Payment ledger** (`guest_payments` + `supplier_payments`) | 5 call sites | `GuestBalances`, `SupplierBalances`, Filament relation managers |

**They do not overlap.** A row in one is invisible to the other. Reports built from either give incomplete pictures of the same business reality.

---

## 4. Determinism matrix — can each flow be replayed?

| # | Flow | Replayable from stored data? | Why / Why not |
|---|---|---|---|
| 2.1 | `RecordTransactionAction` | 🟡 partial | No `source_trigger`; no idempotency key |
| 2.2 | `CashierExchangeService` | 🟡 partial | Pair reconstructable via shared ref; rate not stored |
| 2.3 | `CashierExpenseService` | 🟡 partial | Soft FK string; fuzzy fallback used |
| 2.4 | `BotPaymentService` (top) | 🟢 mostly | Stores presentation snapshot, rate FK, override FK |
| 2.5 | `Fx/BotPaymentService` | 🟢 mostly | Same, but different code path |
| 2.6 | Cashier bot `confirmCashIn` | 🟡 partial | No rate; string `source_trigger` |
| 2.7 | Expense reversal | ❌ no | Fuzzy match; no FK to original row |
| 2.8 | Beds24 external | 🟡 partial | Dedup keys exist; shift attachment is best-effort |
| 2.9 | Octo legacy Booking | ❌ no | Legacy schema; no idempotency |
| 2.10 | Octo new Inquiry | 🟡 partial | Idempotent on `paid_at`; amount drifts from true paid |
| 2.11 | TourCalendar guest pay | ❌ no | No FK, no shift, no ledger linkage |
| 2.12 | TourCalendar supplier pay | ❌ no | Same as 2.11 |
| 2.13 | BookingInquiryResource markPaid | ❌ no | Same |
| 2.14 | Filament CreateCashTransaction | 🟡 partial | Base CreateRecord — no action, no source_trigger |
| 2.15 | GygInquiryWriter | 🟡 partial | Has external_reference; no cash_transactions linkage |
| 2.16 | ReconciliationService | 🟢 yes | Deterministic on Beds24 snapshot |

**Count: 3 green · 9 amber · 4 red**, out of 16 flows.

---

## 5. Classification summary

| Classification | Count | Flows |
|---|---:|---|
| 🟢 SAFE (reference pattern) | **1** | 2.1 `RecordTransactionAction` |
| 🟡 RISKY (works today, fragile) | **6** | 2.2, 2.3, 2.10, 2.14, 2.15, 2.16 |
| 🔴 BROKEN (data integrity risk) | **9** | 2.4 (duplicate), 2.5 (duplicate), 2.6, 2.7, 2.8, 2.9, 2.11, 2.12, 2.13 |

**Over half of money mutation sites are BROKEN by the criteria set out.**

---

## 6. Shadow-ledger reconstruction feasibility

Question: *Using current data, can we reconstruct true financial history?*

**Answer: 🟡 PARTIAL — possible with explicit losses and a layered strategy.**

### 6.1 What can be reconstructed

| Source | Method | Confidence |
|---|---|---|
| Cash payments via cashier/POS bot | `cash_transactions` rows with `source_trigger ∈ {CashierBot, cashier_bot}` | 🟢 high — presentation snapshot, FX, approval chain all stored |
| Cash expenses | `cash_transactions.reference LIKE "expense:*"` joined to `cash_expenses.id` | 🟢 high — when reference is not stripped |
| Currency exchanges | `cash_transactions` pairs sharing `reference LIKE "EX-*"` | 🟢 high |
| External Beds24 payments | `cash_transactions.source_trigger = 'beds24_external'` + `beds24_payment_ref` | 🟡 medium — dedup keys present but no rate snapshot |
| Online payments (Octo) | `guest_payments.reference = octo_transaction_id` | 🟡 medium — amount may not equal paid sum |
| GYG pre-paid | `guest_payments.payment_method = 'gyg'` + `reference = external_reference` | 🟡 medium — same amount issue |
| Supplier payments | `supplier_payments` polymorphic | 🟡 medium — no linkage to cash event, no shift |
| Expense reversals | fuzzy match via `reference LIKE "reversal:*"` | ❌ low — may misattribute |

### 6.2 What is lost

- **Authorship** of rows with `created_by = NULL` (explicitly allowed by 2026-03 migration on `cash_transactions`)
- **Shift attachment** for rows created before `cashier_shift_id` became nullable
- **Rate used** for `CashierExchangeService` transactions (rate not stored)
- **True amount** for Octo, GYG, markPaid paths (price_quoted substitute)
- **Linkage** between online/card payments and a cash-ledger event

### 6.3 Migration strategy implication

A ledger migration **must** run a **reconstruction pass** that:
1. Reads all `cash_transactions` rows → ledger_entries (preserving `source_trigger`, FX columns)
2. Reads all `guest_payments` → ledger_entries with type `payment_in`
3. Reads all `supplier_payments` → ledger_entries with type `payment_out`
4. Reads all `cash_expenses` → ledger_entries with type `expense` (if not already via cash_transactions reference)
5. Reads `booking_payment_reconciliations` → imports Beds24 truth as a *reconciliation adjustment* entry
6. **Flags** rows where authorship / shift / rate / amount is lost — these become `ledger_entries` with a `data_quality` marker for manual review

This is feasible. The data is salvageable. Expect a 2–4 week one-time backfill project.

---

## 7. Candidate "ledger insertion point"

Where should the ledger be introduced to cause least disruption and catch the most flow?

**Primary insertion point:** a new `RecordLedgerEntry` **Action** replaces `RecordTransactionAction` and becomes the **single required contract** for any money write. Every existing flow (the 16 above) is migrated to call it.

**Secondary insertion points (for incremental migration):**

| Order | Target | Reason |
|---|---|---|
| 1 | `BotPaymentService` (both copies) | Highest-value, most-instrumented flow. Pick one copy as canonical, delete the other. |
| 2 | `OctoCallbackController` inquiry path (2.10) | Highest external-facing money flow. Amount-drift bug must be fixed in-flight. |
| 3 | Filament `TourCalendar` pay actions (2.11, 2.12) | Worst violators — highest volume of new `GuestPayment` / `SupplierPayment` rows. |
| 4 | `Beds24WebhookController:693` (2.8) | External ingestion; move into `Beds24/PaymentIngestAction`. |
| 5 | `OwnerBotController::reverseExpenseTransaction` (2.7) | Replace with proper reversal action. |
| 6 | `CashierExchangeService`, `CashierExpenseService` | Already services — refactor to call ledger instead of `CashTransaction` directly. |
| 7 | Everything else in controllers / Filament | Forbid direct `CashTransaction::create` — enforced in Phase 4 via code-style rule. |

---

## 8. Prerequisites for Phase 4 (Ledger design)

These must be **decided**, not just observed:

1. **Pick one `BotPaymentService`** — canonical copy. Delete the other.
2. **Pick one reconciliation path** — `ReconciliationService` vs `Fx/WebhookReconciliationService`.
3. **Resolve the three duplicate `Schema::create` migrations** (`guest_payments`, `booking_fx_syncs`, `fx_manager_approvals`) — decide which schema is live in production and either drop the other migration or convert it to a diff.
4. **Normalize `source_trigger`** — one enum, replacing all string literals.
5. **Normalize `payment_method`** — one enum, multilingual display separated from storage.
6. **Decide amount semantics** — `GuestPayment.amount` = amount_reported_by_source, not `price_quoted`.
7. **Decide whether `cash_transactions` becomes `ledger_entries`** or is retired. Recommended: retire, migrate.
8. **Decide whether Filament pages are allowed to create money rows at all**. Recommended: no — they invoke actions only.

---

## 9. What to refactor first (priority queue)

From the broken list, ordered by **business risk × volume × ease-of-extraction**:

| P | Target | Why first |
|---|---|---|
| P0 | Collapse the two `BotPaymentService` classes | Ambiguous live wiring + duplicated critical flow — this is the #1 silent-bug factory |
| P0 | Resolve 3 duplicate `Schema::create` migrations | Data-corruption risk on any fresh migration run |
| P0 | Normalize `source_trigger` to a single enum | Makes every subsequent refactor measurable |
| P1 | Force all `GuestPayment` / `SupplierPayment` writes through an action | Close the "parallel ledger" gap |
| P1 | Remove `TourCalendar` / `BookingInquiryResource` direct `create` calls | Biggest Filament-writes-money leak |
| P1 | Fix Octo `amount` drift (use `$paidSum` not `price_quoted`) | Simple, silent-data-loss fix |
| P2 | Replace `OwnerBotController::reverseExpenseTransaction` fuzzy match with proper FK | Low volume, high correctness |
| P2 | Move Beds24 webhook ingestion into `Beds24/PaymentIngestAction` | Medium-risk rewrite, testable |
| P2 | Move `RoomCleaning` / `RoomRepair` / `RoomIssue` into unified task core | Outside money, but unblocks operational-core design |

---

## 10. Conclusion — is this system recoverable?

**Yes.** Phase 3.5 confirms what Phase 2 sketched:

- The architectural mess is **real** — 9 flows are BROKEN by our criteria.
- The data is **recoverable** — 🟢 for cash bot flows, 🟡 for online/external, ❌ only for reversals and a few legacy paths.
- A **proto-ledger already exists** inside `cash_transactions`; purification, not invention, is the Phase 4 task.
- **Every risk is traceable to a specific file:line** — no ghosts.

Phase 4 (`TARGET_ARCHITECTURE.md`) has everything it needs to design a single `RecordLedgerEntry` action, a single `LedgerEntry` aggregate, an immutable event taxonomy, and a migration path that preserves history.

---

**Next:** `VIOLATIONS.md` (Phase 3) — reuse findings above to produce the generic layer-violation report with `file:line` precision across non-money domains too.
