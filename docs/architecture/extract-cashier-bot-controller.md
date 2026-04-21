# Refactor plan — extract `CashierBotController`

**Status:** PLAN (awaiting approval)  
**Scope gate:** cashier-only runtime. `CashierBotController` is dispatched **only** by `TelegramWebhookController` → `handleWebhook()` on the `@cashier_bot` path. Zero tour callers. Zero shared-runtime change. Safe under the scope gate.  
**Architectural authority:** `docs/architecture/PRINCIPLES.md` + `docs/architecture/LAYER_CHEAT_SHEET.md`.

---

## 0. Status of prior work (A1, B1, B2, B3)

**Completed (in production):**

- **A1** (commit f365219): Idempotency guard for `confirm_cash_in` callback — prevents duplicate cash-in bookings on Telegram retry.
- **B1** (commit 3773a69): Swap `latest('id')` → `latest('created_at')` on `ShiftHandover` carry-forward query in `openShift()`. Ensures most recent closed shift's balance is carried forward, not just the one with highest PK.
- **B2** (commit 3773a69): Guard open-shift drawer singleton with transaction + row lock. Prevents two concurrent cashiers from opening overlapping shifts on the same drawer.
- **B3** (commit eddf39d): Add `UNIQUE(cashier_shift_id, currency)` constraint on `beginning_saldos` table. Prevents duplicate carry-forward rows for the same shift + currency pair.

**Blocked (not in scope of this refactor):**

- **A2** (open, future work): Manager FX override approval has no resolver. When `BotPaymentService` detects variance > 2% and override_tier is 'manager', it throws `ManagerApprovalRequiredException` (line 140–143 of `BotPaymentService`). No approval flow exists in `CashierBotController`; must be routed through `OwnerBotController` (NOT YET IMPLEMENTED). Currently blocks payment with no resolution path. **This refactor will NOT touch `confirmPayment()` or the entire FX override path.** A2 is a behaviour change and will be handled in a separate ticket after this structural extraction is complete.

---

## 1. Why this refactor

`CashierBotController` is **1,865 LOC in a single controller class** that violates 4 of the 11 principles at once:

| Principle | Violation |
|---|---|
| **P3** Business logic in Actions/Services | Fourteen workflow handlers live inside the controller (auth, open shift, payment flow with FX, expense flow, exchange flow, cash-in, close shift with photo, transactions view, guide) |
| **P4** Controllers/Jobs are thin | 1,865 LOC is the fattest controller in the codebase (baseline: `fat-controller` violation) |
| **P5** Single source of truth for rules | Session state mutations (`$session->update([...'state'=>...])`) are called ~40 times across the controller; shift balance logic, shift-close notifications, and booking snapshot extraction are scattered |
| **P6/P7** External systems in adapters | Mostly OK (services are already injected), but some direct model queries (e.g., `getShift()`, `getBal()`) and Beds24 snapshot logic leak domain concerns |

It is the **largest single violation** currently in the codebase besides the deleted `ProcessBookingMessage`. Extracting it is pure cashier-only work — no POS runtime, no tour runtime, no owner bot is touched (except for the pre-existing fan-out at line 206–209).

---

## 2. Current shape inventory (intent/callback/state map)

### Intent 1: Auth / phone contact

**Entry points:**  
- `handleMessage()` with `contact` field (line 127–160) → `handleAuth()` (line 162–176)

**Callback data:** *none (message-based)*

**Session state prefixes:** `null` (no session yet)

**Controller methods:**  
- `handleAuth()` (14 LOC)

**Services called:**  
- `User::where('phone_number', 'LIKE', ...)`
- `TelegramPosSession::updateOrCreate()`

**Models read/written:**  
- `User` (read by phone suffix)
- `TelegramPosSession` (create/update)

**LOC span:** 162–176 (14 LOC) + `phoneKb()` (75 LOC, line 1639–1714)

**Idempotency:** Not idempotent (re-auth creates a new session, overwrites prior one). No `IDEMPOTENT_ACTIONS` entry.

---

### Intent 2: Main menu / state bootstrap

**Entry points:**  
- `callback_data='menu'` (line 241)
- `/start` or `/menu` command (line 155)
- Fallback after most workflows (e.g., after payment confirm, expense confirm)

**Callback data:** `menu`

**Session state prefixes:** → transitions to `main_menu`

**Controller methods:**  
- `showMainMenu()` (51 LOC, line 178–227)

**Services called:**  
- `getShift()` (indirect)
- `getBal()` (indirect)
- `fmtBal()` (indirect)
- `menuKb()` (indirect)

**Models read/written:**  
- `CashierShift` (read for open shift check)

**LOC span:** 178–227 (51 LOC) + helpers

**Idempotency:** Not idempotent (re-entry to main menu is safe; read-only).

---

### Intent 3: Open shift

**Entry points:**  
- `callback_data='open_shift'` (line 233)

**Callback data:** `open_shift`

**Session state prefixes:** → `main_menu`

**Controller methods:**  
- `openShift()` (68 LOC, line 355–422)

**Services called:**  
- `CashierShiftService` (implicit; direct DB operations used instead)
- `CashDrawer::where()`, `CashierShift::create()`, `ShiftHandover::...->first()`, `BeginningSaldo::create()`

**Models read/written:**  
- `CashDrawer` (read + lock for update)
- `CashierShift` (create)
- `ShiftHandover` (read)
- `BeginningSaldo` (create)

**LOC span:** 355–422 (68 LOC including transaction + singleton check from B2)

**Idempotency:** Guard is `getShift($s->user_id)` check at line 357. Returns early if cashier already has an open shift. No `IDEMPOTENT_ACTIONS` entry (guarded by business logic, not callback idempotency table).

---

### Intent 4: Show balance

**Entry points:**  
- `callback_data='balance'` (line 237)

**Callback data:** `balance`

**Session state prefixes:** *none* (instant reply, no state mutation)

**Controller methods:**  
- `showBalance()` (64 LOC, line 1129–1192)

**Services called:**  
- `getShift()` (helper)
- `getBal()` (helper)
- `fmtBal()` (helper)

**Models read/written:**  
- `CashierShift` (read)

**LOC span:** 1129–1192 (64 LOC)

**Idempotency:** Not idempotent (read-only; re-entry is safe).

---

### Intent 5: Payment flow (FX → confirm)

**Entry points:**  
- `callback_data='payment'` → `startPayment()` (line 427–460, line 234)
- State dispatch in `handleState()` (line 335–351): `payment_*` states

**Callback data:** `payment`, `guest_*`, `cur_*`, `fx_confirm_amount`, `rate_use_reference`, `method_*`, `confirm_payment`

**Session state prefixes:** `payment_room`, `payment_amount`, `payment_exchange_rate`, `payment_fx_amount`, `payment_fx_override_reason`

**Controller methods (in flow order):**  
1. `startPayment()` (34 LOC, 427–460) — shift check, fetch in-house guests, init state
2. `fetchInHouseGuests()` (27 LOC, 462–488) — Beds24 API call + fallback
3. `hPayRoom()` (65 LOC, 492–556) — handle room ID input
4. `selectGuest()` (69 LOC, 531–599) — callback dispatch for guest selection
5. `hPayAmt()` (127 LOC, 619–745) — handle amount input + currency selection branch
6. `selectCur()` (103 LOC, 634–736) — callback dispatch for currency selection
7. `askExchangeRate()` (57 LOC, 676–732) — FX rate presentation + reference rate
8. `hPayRate()` (79 LOC, 713–791) — handle manual rate entry
9. `acceptReferenceRate()` (52 LOC, 728–779) — callback for using reference rate
10. `proceedToPaymentMethod()` (26 LOC, 739–764) — FX override decision point
11. `fxConfirmAmount()` (27 LOC, 755–781) — callback for FX override confirmation
12. `hPayFxAmount()` (34 LOC, 773–806) — handle FX override amount
13. `askFxOverrideReason()` (56 LOC, 811–866, private) — prompt for override reason + tier evaluation (A2 decision point)
14. `blockFxOverride()` (57 LOC, 830–887, private) — evaluate override tier; blocks with `ManagerApprovalRequiredException` if tier='manager'
15. `hPayFxOverrideReason()` (40 LOC, 851–890) — handle reason input
16. `selectMethod()` (34 LOC, 864–897) — callback dispatch for payment method selection
17. `confirmPayment()` (52 LOC, 908–959) — idempotent confirm; calls `BotPaymentService::recordPayment()`

**Services called:**  
- `BotPaymentService::recordPayment()` (in `confirmPayment()`)
- `ExchangeRateService` (implicit in balance logic)
- `Beds24BookingService::liveGuests()` (in `fetchInHouseGuests()`)

**Models read/written:**  
- `Beds24Booking` (read/write via import)
- `CashierShift` (read)
- `CashTransaction` (write via `BotPaymentService`)
- `TelegramPosSession` (read/write state)

**LOC span:** 427–959 (533 LOC total across all substeps)

**Idempotency:** `confirm_payment` is in `IDEMPOTENT_ACTIONS` (line 179). Idempotency guard at line 213–227; `callbackId` required for confirmation. A2 decision blocks the flow for manager tier; no approval resolver exists.

---

### Intent 6: Expense flow (category → amount → description → confirm)

**Entry points:**  
- `callback_data='expense'` → `startExpense()` (line 235)

**Callback data:** `expense`, `expcat_*`, `confirm_expense`

**Session state prefixes:** `expense_category`, `expense_amount`, `expense_desc` (note: state names don't match; handled by method dispatch)

**Controller methods (in flow order):**  
1. `startExpense()` (99 LOC, 963–1061) — shift check, fetch categories, init state
2. `selectExpCat()` (70 LOC, 978–1047) — callback dispatch for category selection
3. `hExpAmt()` (26 LOC, 989–1014) — handle amount input
4. `hExpDesc()` (23 LOC, 1001–1023) — handle description input
5. `confirmExpense()` (30 LOC, 1019–1048) — idempotent confirm; calls `CashierExpenseService::recordExpense()`

**Services called:**  
- `CashierExpenseService::recordExpense()`

**Models read/written:**  
- `CashierShift` (read)
- `ExpenseCategory` (read)
- `CashExpense` (write via service)
- `TelegramPosSession` (read/write state)

**LOC span:** 963–1048 (86 LOC total)

**Idempotency:** `confirm_expense` is in `IDEMPOTENT_ACTIONS` (line 180). Idempotency guard at line 213–227; `callbackId` required.

---

### Intent 7: Exchange flow (in-currency → amount → out-currency → amount → confirm)

**Entry points:**  
- `callback_data='exchange'` → `startExchange()` (line 236)

**Callback data:** `exchange`, `excur_*`, `exout_*`, `confirm_exchange`

**Session state prefixes:** `exchange_in_currency`, `exchange_in_amount`, `exchange_out_currency`, `exchange_out_amount`

**Controller methods (in flow order):**  
1. `startExchange()` (99 LOC, 1354–1452) — shift check, init state
2. `selectExCur()` (83 LOC, 1370–1452) — callback dispatch for in-currency selection
3. `hExInAmt()` (25 LOC, 1379–1403) — handle in-amount input
4. `selectExOutCur()` (31 LOC, 1401–1431) — callback dispatch for out-currency selection
5. `hExOutAmt()` (31 LOC, 1410–1440) — handle out-amount input
6. `confirmExchange()` (36 LOC, 1437–1472) — idempotent confirm; calls `CashierExchangeService::recordExchange()`

**Services called:**  
- `CashierExchangeService::recordExchange()`

**Models read/written:**  
- `CashierShift` (read)
- `CashTransaction` (write via service)
- `TelegramPosSession` (read/write state)

**LOC span:** 1354–1472 (119 LOC total)

**Idempotency:** `confirm_exchange` is in `IDEMPOTENT_ACTIONS` (line 182). Idempotency guard at line 213–227; `callbackId` required.

---

### Intent 8: Cash-in flow (amount → confirm)

**Entry points:**  
- `callback_data='cash_in'` → `startCashIn()` (line 238)

**Callback data:** `cash_in`, `confirm_cash_in`

**Session state prefixes:** `cash_in_amount`

**Controller methods (in flow order):**  
1. `startCashIn()` (52 LOC, 1053–1104) — shift check, init state
2. `hCashInAmt()` (46 LOC, 1065–1110) — handle amount input
3. `confirmCashIn()` (46 LOC, 1083–1128) — idempotent confirm; A1 guards against duplicate; calls `CashierShiftService::recordCashIn()`

**Services called:**  
- `CashierShiftService::recordCashIn()` (or similar method handling cash-in)

**Models read/written:**  
- `CashierShift` (read/write)
- `CashTransaction` (write via service)
- `TelegramPosSession` (read/write state)

**LOC span:** 1053–1128 (76 LOC total, with A1 guard in place)

**Idempotency:** `confirm_cash_in` is in `IDEMPOTENT_ACTIONS` (line 183). Idempotency guard at line 213–227 + A1-specific guard inside method. `callbackId` required.

---

### Intent 9: Close shift (count → count → count → photo → confirm)

**Entry points:**  
- `callback_data='close_shift'` → `startClose()` (line 240)
- Photo message during close (line 154) → `handleShiftPhoto()`

**Callback data:** `close_shift`, `confirm_close`

**Session state prefixes:** `shift_count_uzs`, `shift_count_usd`, `shift_count_eur`, `shift_close_photo`

**Controller methods (in flow order):**  
1. `startClose()` (67 LOC, 1196–1262) — shift check, init state, ask for first currency count
2. `hCount()` (63 LOC, 1206–1268) — handle count input for a given currency; loop to next currency or photo prompt
3. `showCloseConfirm()` (29 LOC, 1225–1253) — display summary + photo upload button
4. `handleShiftPhoto()` (40 LOC, 1246–1285) — handle photo upload for shift close (concurrent with text flow)
5. `confirmClose()` (30 LOC, 1255–1284) — idempotent confirm; calls `CashierShiftService::closeShift()` + `sendShiftCloseNotifications()`
6. `sendShiftCloseNotifications()` (41 LOC, 1287–1327) — send notifications to owner + other cashiers

**Services called:**  
- `CashierShiftService::closeShift()`
- `OwnerAlertService` (in notifications)
- `Beds24BookingService` (in notifications for live data)

**Models read/written:**  
- `CashierShift` (read/write status)
- `ShiftHandover` (create)
- `EndSaldo` (create)
- `TelegramPosSession` (read/write state)
- `Beds24Booking` (read for notifications)

**LOC span:** 1196–1327 (131 LOC total)

**Idempotency:** `confirm_close` is in `IDEMPOTENT_ACTIONS` (line 184). Idempotency guard at line 213–227; `callbackId` required.

---

### Intent 10: Show my transactions

**Entry points:**  
- `callback_data='my_txns'` (line 255)

**Callback data:** `my_txns`

**Session state prefixes:** *none* (instant reply, no state mutation)

**Controller methods:**  
- `showMyTransactions()` (60 LOC, 1143–1202)

**Services called:**  
- `CashTransaction::where()` queries (implicit)

**Models read/written:**  
- `CashTransaction` (read, filter by user + date range)

**LOC span:** 1143–1202 (60 LOC)

**Idempotency:** Not idempotent (read-only; re-entry is safe).

---

### Intent 11: Show guide / guide topics

**Entry points:**  
- `callback_data='guide'` (line 256)
- `callback_data='guide_*'` (line 257)

**Callback data:** `guide`, `guide_<topic>`

**Session state prefixes:** *none* (instant reply, no state mutation)

**Controller methods:**  
- `showGuide()` (26 LOC, 1760–1785)
- `showGuideTopic()` (29 LOC, 1774–1802)

**Services called:**  
- *none*

**Models read/written:**  
- *none*

**LOC span:** 1760–1802 (55 LOC total)

**Idempotency:** Not idempotent (read-only; re-entry is safe).

---

### Intent 12: Answer callback query (shared utility)

**Entry points:**  
- *implicit* — called by `handleCallback()` before intent dispatch (line 204)

**Callback data:** *all callbacks* (dismisses the Telegram loading indicator)

**Session state prefixes:** *none*

**Controller methods:**  
- `aCb()` (20 LOC, 1734–1753)

**Services called:**  
- `BotResolver`, `TelegramTransport`

**Models read/written:**  
- *none*

**LOC span:** 1734–1753 (20 LOC)

**Idempotency:** Not idempotent (best-effort, idempotent in effect).

---

### Intent 13: Alert owner on error (shared utility)

**Entry points:**  
- *implicit* — called by catch blocks in `processUpdate()`, `confirmPayment()`, etc.

**Callback data:** *none*

**Session state prefixes:** *none*

**Controller methods:**  
- `alertOwnerOnError()` (26 LOC, 1745–1770)

**Services called:**  
- `OwnerAlertService`
- `User::find()` (for user name)

**Models read/written:**  
- `User` (read)

**LOC span:** 1745–1770 (26 LOC)

**Idempotency:** Not idempotent (notification-sending side effect).

---

### Shared helpers / transport

**Entry points:** Called by all intents

**Controllers methods:**  
- `send()` (59 LOC, 1719–1777) — wrapped message sending via `botResolver` + `transport`
- `getShift()` (130 LOC, 1474–1603) — cashier's open shift lookup (used by ~14 intents)
- `getBal()` (83 LOC, 1527–1609) — compute shift balance from transactions + saldo
- `fmtBal()` (28 LOC, 1610–1637) — format balance as string
- `menuKb()` (29 LOC, 1639–1667) — build main-menu inline keyboard (shift status aware)
- `parseAmountCurrency()` (28 LOC, 1660–1687) — parse "100 USD" → [100, 'USD']
- `phoneKb()` (75 LOC, 1639–1714) — build phone-auth keyboard

**Services called:**  
- `BotResolver`, `TelegramTransport` (in `send()`)

**Models read/written:**  
- `CashierShift`, `CashTransaction`, `CashExpense`, `CashIncome` (read in balance logic)

**LOC span:** 1474–1777 (sum ~460 LOC)

---

### Summary intent table

| #  | Intent | Entry | LOC | Idempotent | State prefixes | Key risk |
|----|--------|-------|-----|------------|---|---|
| 1 | Auth | contact | 14+75 | — | none | phone LIKE match collision |
| 2 | Main menu | callback/command | 51 | — | main_menu | — |
| 3 | Open shift | callback | 68 | guard | main_menu | B2 lock contention |
| 4 | Show balance | callback | 64 | — | none | — |
| 5 | Payment | callback+state | 533 | yes (A2 blocked) | payment_* | A2 approval missing |
| 6 | Expense | callback+state | 86 | yes | expense_* | — |
| 7 | Exchange | callback+state | 119 | yes | exchange_* | — |
| 8 | Cash-in | callback+state | 76 | yes (A1) | cash_in_* | — |
| 9 | Close shift | callback+state+photo | 131 | yes | shift_count_*, shift_close_photo | photo handler coupling |
| 10 | Show transactions | callback | 60 | — | none | — |
| 11 | Guide | callback | 55 | — | none | — |
| 12 | Answer callback | implicit | 20 | — | none | — |
| 13 | Alert on error | implicit | 26 | — | none | — |
| Shared | Transport + helpers | implicit | 460 | — | none | — |

**Total controller LOC:** 1,865 (1 class)  
**Total extractable LOC:** ~1,700 (excluding shared utilities)  
**Target after extraction:** ~200 (thin router + shared utilities on base class or service)

---

## 3. Extraction sequence (atomic commits, phases)

Each phase = one independent commit. Ordering rules:
1. **Lowest-risk first** (read-only paths before money paths).
2. **A2-coupled intents LAST and flagged** (FX approval flow untouched).
3. **Auth / phone contact early** — gates everything else.
4. **Each phase leaves the system deployable.**
5. **Shared utilities** — either stay on base class or move to service; justified below.

---

### Phase 0: Golden-master tests + baseline audit

**Scope:**  
- Create `tests/Feature/CashierBot/GoldenMasterTest.php` with synthetic + real Telegram updates
- Run `scripts/arch-lint.sh` to confirm baseline (should show 1 violation: `fat-controller`)

**Dependency:** None

**Risk:** L (tests only, zero code changes)

**Test plan:**  
- Hand-craft synthetic Telegram updates for each intent (auth, open_shift, payment callback, etc.)
- Optionally capture anonymised real updates from production logs
- Assert reply text matches golden fixture

**Commits:** 1

---

### Phase 1: Extract low-risk read-only intents

**Actions extracted:**  
1. `ShowBalanceAction` (64 LOC)
2. `ShowMyTransactionsAction` (60 LOC)
3. `ShowGuideAction` (55 LOC)

**Scope:**  
- New `app/Actions/CashierBot/Handlers/` namespace
- Each Action has single `execute()` method
- Constructor-injected dependencies (shift lookup, transaction query, etc.)
- Return formatted string (reply text)
- No state mutation
- No money side effects

**Dependency:** Phase 0 golden master (for regression testing)

**Risk:** L (read-only; no state change; covered by golden master)

**Test plan:**  
- Golden master should still be green
- New unit tests: one happy path per Action
- `arch-lint --staged` should show no new violations

**Rollback:** Single `git revert` removes 3 Action files; router calls revert to inlined methods

**Commits:** 1 (one per action, or 1 batched)

---

### Phase 2: Extract auth flow

**Actions extracted:**  
1. `HandleAuthAction` (14 LOC)

**Scope:**  
- Phone contact parsing + User lookup + session create
- Constructor-injected: `User` model (implicit, via Query builder)
- Return Telegram response (or void)
- Session mutation: `TelegramPosSession::updateOrCreate()`

**Dependency:** Phase 0 (golden master)

**Risk:** L (auth is isolated; gates everything else, so good to extract early)

**Test plan:**  
- Golden master auth path should pass
- Unit test: valid phone → session created; invalid phone → error reply

**Rollback:** Single `git revert` removes 1 Action file; router call reverts to inlined method

**Commits:** 1

---

### Phase 3: Extract main menu + shared keyboard helpers

**Actions extracted:**  
1. `ShowMainMenuAction` (51 LOC)

**Shared utilities (keep on controller or move to service?):**  
- `menuKb()` (29 LOC) — build inline keyboard based on shift status
- `phoneKb()` (75 LOC) — build phone auth keyboard
- `send()` (59 LOC) — transport wrapper

**Decision: Shared utilities stay on controller base class (for now)**

Rationale:  
- `menuKb()` and `phoneKb()` are keyboard builders, not domain logic — they're UI formatting, not extractable as standalone Actions.
- `send()` is the action's reply transport. Every extracted action must send replies back to Telegram. Decision options:
  - **Option A** (chosen): Keep `send()` on controller, inject controller instance into each Action. Pro: simple, matches ProcessBookingMessage pattern. Con: Actions depend on controller.
  - **Option B**: Extract `send()` to a `CashierMessenger` service. Pro: Actions decouple from controller. Con: adds new service layer, more files.
  - **Option C**: Pass `botResolver` + `transport` directly to each Action. Pro: maximum decoupling. Con: verbose parameter passing, each Action duplicates `send()` logic.
  
We choose **Option A** (controller base class) for parity with `ProcessBookingMessage` — extract Actions, keep UI delivery on controller.

**Scope:**  
- Extract `ShowMainMenuAction`
- Keep `menuKb()`, `phoneKb()`, `send()`, `aCb()`, `alertOwnerOnError()` on controller
- Constructor-injected into Action: controller instance (or just call static on controller?)

Wait — Actions should not depend on controller. Reconsider.

**Revised decision: Create `CashierMessenger` service for reply transport**

- `send($chatId, $text, $kb, $type)` → extracted to `CashierMessenger::send()`
- `aCb()` → extracted to `CashierMessenger::answerCallback()`
- `alertOwnerOnError()` → stays on controller (it's an error handler, not action reply)
- `menuKb()`, `phoneKb()` → stay on controller (keyboard building is controller concern)

Rationale:  
- Actions should inject `CashierMessenger`, not depend on the controller.
- Messenger is not a Service (per P3 — Services are external-system adapters). It's a transport utility. OK to live in `app/Services/` or `app/Support/`.
- `menuKb()` and `phoneKb()` stay on controller because they're tightly coupled to controller state (shift lookup, session state, etc.).

**Scope (revised):**  
1. Create `app/Services/CashierMessenger.php` (wrapper around `botResolver` + `transport`)
2. Extract `ShowMainMenuAction`
3. Constructor-inject `CashierMessenger` + `CashierShiftService` into `ShowMainMenuAction`

**Dependency:** Phase 0 (golden master); Phase 1 (read-only actions, for pattern precedent)

**Risk:** M (introduces new service; needs careful Messenger injection testing)

**Test plan:**  
- Golden master should still be green
- Unit tests: `ShowMainMenuAction` + `CashierMessenger::send()` (mock transport)
- Integration test: CashierMessenger calls real bot resolver once (no actual Telegram send)

**Rollback:** `git revert` removes Action + Messenger; router calls revert to inlined methods + controller `send()`

**Commits:** 2 (CashierMessenger service + ShowMainMenuAction)

---

### Phase 4: Extract money-path helper methods

**Actions extracted:**  
- None (these are helpers, not entry points)

**Scope:**  
- Move to dedicated helper/utility class: `app/Support/CashierSessionManager` or `app/Support/CashierBalanceCalculator`

**Helper methods:**  
1. `getShift()` (130 LOC) — lookup user's open shift
2. `getBal()` (83 LOC) — compute balance from DB
3. `fmtBal()` (28 LOC) — format balance string

**Decision: Extract to `CashierBalanceCalculator` service**

- `getShift()` → method on service (or keep on controller, call directly)
- `getBal()` → method on service
- `fmtBal()` → method on service

Rationale:  
- These are domain helpers (shift lookup, balance calculation, formatting).
- They're called by ~14 intents. Moving them to a service makes each Action smaller + reusable.
- `CashierBalanceCalculator` is a Service (per P3 — it's a single-purpose calculator, not an external-system adapter, but similar responsibility pattern).

**Scope:**  
1. Create `app/Services/CashierBalanceCalculator.php` (wrap logic from 3 methods)
2. Inject into Actions that need balance checks
3. Delete methods from controller

**Dependency:** Phase 3 (Messenger injected; uses similar pattern)

**Risk:** M (introduces dependency injection pattern for helpers; need to thread through Action constructors)

**Test plan:**  
- Golden master should still pass (behavior unchanged)
- Unit tests: each method on `CashierBalanceCalculator`
- Integration test: Action → Messenger → correct balance shown

**Rollback:** `git revert` removes service; methods move back to controller (tedious but mechanical)

**Commits:** 1

---

### Phase 5: Extract auth + session management

**Actions extracted:**  
1. `HandleAuthAction` (already started in Phase 2; complete here)

**Shared session utility:**  
- Decision: where do session state mutations live? Currently `$session->update([...'state'=>...])` scattered ~40 times across controller.

**Option A (chosen):** Keep state mutations inline in each Action. Pro: each Action owns its state transitions. Con: scattered, hard to audit all state paths.

**Option B:** Extract `SessionStateMachine` service with `transitionTo($session, 'new_state', $data)`. Pro: centralized state audit trail. Con: adds abstraction layer, may be overkill if states are simple linear flows.

We choose **Option A** (inline) for simplicity in Phase 1–4. Phase N (after all actions extracted) can introduce a centralized state machine if needed.

But Session mutations are coupled to Action logic. Document this intentional seam:
- Each Action receives `$session` as parameter
- Action is responsible for `$session->update(['state'=>...])` calls within its domain
- `SessionStateMachine` is a future optimization, not a blocker

**Scope:**  
- No new service in this phase
- Document seam in extraction plan (section 5)

**Dependency:** Phase 3 (Messenger in place)

**Risk:** L (pattern already established in Phase 3)

**Test plan:**  
- Golden master: auth path should create session with correct state
- Unit test: session state transitions verified

**Commits:** 0 (no structural change; auth action already extracted in Phase 2)

---

### Phase 6: Extract open-shift action

**Actions extracted:**  
1. `OpenShiftAction` (68 LOC, includes B2 singleton guard from production)

**Scope:**  
- Transaction + row lock (from B2) stays inline
- Carry-forward logic (from B1: `latest('created_at')`) stays inline
- Constructor-inject: `CashierShiftService`, `CashierBalanceCalculator`, `CashierMessenger`

**Dependency:** Phase 4 (balance calculator); Phase 3 (messenger)

**Risk:** M (money operation; complex transaction logic; B2 guard must remain correct)

**Test plan:**  
- Golden master: open shift path verifies shift created + balance shown
- Existing test suite: `OpenShiftSingleton`, `BeginningSaldoUniqueness` must pass
- Unit test: concurrent open-shift callback guard (mutex)

**Rollback:** `git revert` removes Action; router calls revert to inlined method; transaction logic unchanged

**Commits:** 1

---

### Phase 7: Extract expense flow (low-risk money path)

**Actions extracted:**  
1. `StartExpenseAction` (99 LOC)
2. `SelectExpenseCategoryAction` (70 LOC)
3. `ConfirmExpenseAction` (30 LOC + idempotency guard)

**Scope:**  
- Shift check
- Category selection dispatch
- Amount + description input
- Idempotency guard + `CashierExpenseService::recordExpense()` call

**Dependency:** Phase 6 (balance calculator for shift check); Phase 3 (messenger)

**Risk:** M (money operation; idempotency guard in place)

**Test plan:**  
- Golden master: expense flow should produce correct reply
- Existing test suite: must remain green (expense tests)
- Unit test: idempotency guard + duplicate action blocking

**Rollback:** `git revert` removes 3 Actions; router calls revert to inlined methods

**Commits:** 1 (or 1 per action; recommend 1 batched)

---

### Phase 8: Extract cash-in flow (money path with A1 guard)

**Actions extracted:**  
1. `StartCashInAction` (52 LOC)
2. `ConfirmCashInAction` (46 LOC, includes A1 idempotency guard from production)

**Scope:**  
- Shift check
- Amount input
- Idempotency guard (A1 in production) + `CashierShiftService::recordCashIn()` call
- Constructor-inject: `CashierShiftService`, `CashierMessenger`

**Dependency:** Phase 6 (balance calculator)

**Risk:** M (money operation; A1 guard from production; critical test coverage required)

**Test plan:**  
- Golden master: cash-in flow
- Existing test suite: `CashInIdempotency` test must pass (this is A1)
- Unit test: duplicate callback blocking (A1 seam)

**Rollback:** `git revert` removes 2 Actions; router calls revert to inlined methods; A1 guard remains (in `telegram_processed_callbacks` table)

**Commits:** 1

---

### Phase 9: Extract exchange flow (money path)

**Actions extracted:**  
1. `StartExchangeAction` (99 LOC)
2. `SelectExchangeInCurrencyAction` (83 LOC)
3. `SelectExchangeOutCurrencyAction` (31 LOC)
4. `ConfirmExchangeAction` (36 LOC + idempotency guard)

**Scope:**  
- Shift check
- In-currency + amount selection
- Out-currency + amount selection
- Idempotency guard + `CashierExchangeService::recordExchange()` call

**Dependency:** Phase 6 (balance calculator); Phase 3 (messenger)

**Risk:** M (money operation; 4 sub-actions; idempotency guard)

**Test plan:**  
- Golden master: exchange flow
- Existing test suite: must remain green
- Unit test: idempotency guard + multi-step flow

**Rollback:** `git revert` removes 4 Actions; router calls revert to inlined methods

**Commits:** 1 (batched)

---

### Phase 10: Extract close-shift flow (most complex money path)

**Actions extracted:**  
1. `StartCloseShiftAction` (67 LOC)
2. `HandleShiftCountInputAction` (63 LOC)
3. `ShowCloseConfirmAction` (29 LOC)
4. `HandleShiftPhotoAction` (40 LOC)
5. `ConfirmCloseShiftAction` (30 LOC + idempotency guard)
6. `SendShiftCloseNotificationsAction` (41 LOC, internal helper)

**Scope:**  
- Shift check + state machine (uzs count → usd count → eur count → photo → confirm)
- Photo upload handling (lives in `handleMessage()` currently, coupled to state check)
- Idempotency guard + `CashierShiftService::closeShift()` call
- Notifications to owner + other cashiers (service call)

**Seam: Photo handler coupling**

Photo is handled in `handleMessage()` as a special case: "if state is `shift_close_photo`" (line 154). This is a state-machine seam. When extracted, the photo handler must still run inline during `handleMessage()` logic (before state dispatch in `handleState()`), or we risk breaking the flow.

**Decision: Keep photo dispatch in controller `handleMessage()` pre-check**

The photo handler Action is invoked by the router (in `handleMessage()` before state dispatch), not extracted into `handleState()`. This preserves the timing and message flow.

Actually, cleaner: extract the photo handler, but keep the state check in `handleMessage()` for dispatch:

```php
// in handleMessage()
if ($photo && $session->state === 'shift_close_photo') {
    return app(HandleShiftPhotoAction::class)->execute($session, $chatId, $photo);
}
```

This way the photo handler is extracted, but the state check remains in the router (which is fine — the router is thin, just dispatches).

**Scope (revised):**  
1. Extract 5 Actions (start, count input, show confirm, photo handler, confirm)
2. Keep `SendShiftCloseNotifications` as private helper or extract to service (probably service, it's complex)
3. Photo dispatch check stays in `handleMessage()` router (before state dispatch)

**Dependency:** Phase 6 (balance calculator); Phase 3 (messenger); Phase 4 or later (notifications service)

**Risk:** H (most complex flow; photo handler coupling; notifications async; B2 lock contention in concurrent shifts)

**Test plan:**  
- Golden master: close-shift flow with photo
- Existing test suite: all close-shift tests must pass
- Unit test: state machine flow (uzs → usd → eur → photo → confirm)
- Unit test: idempotency guard blocks duplicate confirm
- Integration test: notifications sent to owner

**Rollback:** `git revert` removes 5–6 Actions; router calls revert to inlined methods; photo dispatch reverts to inline check

**Commits:** 2 (Actions + notifications service)

---

### Phase 11: Extract payment flow (BLOCKED by A2)

**Status: DO NOT EXTRACT IN THIS PHASE**

**Actions (not extracted):**  
- `StartPaymentAction` (34 LOC)
- `FetchInHouseGuestsAction` (27 LOC)
- Payment state handlers: `hPayRoom`, `selectGuest`, `hPayAmt`, `selectCur`, etc. (533 LOC total)
- `ConfirmPaymentAction` (52 LOC, idempotent)

**Reason for deferral:**  
- A2 BUG: Manager FX override approval has no resolver. Payment flow calls `BotPaymentService::recordPayment()`, which throws `ManagerApprovalRequiredException` for manager-tier overrides (line 140–143 of `BotPaymentService`). No approval path exists in `CashierBotController`; must route through `OwnerBotController` (NOT YET IMPLEMENTED).
- Extracting this flow before A2 is fixed risks breaking production. A2 is a behaviour change (adding approval flow), not a structural refactor.
- Defer payment extraction until A2 is resolved in a separate ticket.

**Future plan (separate ticket A2):**  
1. Implement manager FX override approval flow in `OwnerBotController` (route from `CashierBotController`).
2. Once A2 is resolved, extract payment flow Actions as a follow-up refactor.
3. Or: extract payment flow in this refactor, but flag `ConfirmPaymentAction` with TODO and return early with "not implemented" message until A2 is done.

We choose **full deferral**: do not extract payment flow in this refactor. Leave it inline on the controller. Document the A2 blocker explicitly in the plan.

**Dependency:** A2 fix (outside scope of this refactor)

**Risk:** H (blocker until A2 is fixed)

**Commits:** 0 (deferred)

---

### Phase 12: Collapse the router

**Scope:**  
- `handleCallback()` becomes a thin dispatcher: match callback_data → call Action → no more inline logic
- `handleState()` becomes a thin dispatcher: match state → call Action → no more inline logic
- `processUpdate()` and `handleMessage()` remain mostly as-is (webhook entry + routing)
- Constructor injection list pared down (remove injections only used by extracted Actions)

**Target size:** router ≤ 200 LOC (from 1,865)

**Dependency:** Phases 1–10 (all non-A2 actions extracted)

**Risk:** L (structural only; each Action is already extracted; pure refactoring)

**Test plan:**  
- Golden master: all non-A2 paths should be green
- `arch-lint --staged`: should show zero violations (fat-controller gone)

**Commits:** 1

---

### Phase 13: Arch-lint baseline refresh + final commit

**Scope:**  
- Run `scripts/arch-lint.sh` to confirm violations dropped
- Confirm `fat-controller` violation on `CashierBotController` is gone (or at least reduced from 1,865 LOC to ~200)
- Regenerate baseline if needed: `scripts/arch-lint.sh --regen-baseline > scripts/arch-lint-baseline.txt`
- Commit baseline update

**Dependency:** Phase 12 (router collapsed)

**Risk:** L (audit only)

**Commits:** 1

---

## Summary of extraction phases

| Phase | Name | Actions | Commits | Risk | Blocker |
|-------|------|---------|---------|------|---------|
| 0 | Golden master + baseline | tests | 1 | L | — |
| 1 | Read-only paths | 3 (balance, transactions, guide) | 1 | L | 0 |
| 2 | Auth flow | 1 (auth) | 1 | L | 0 |
| 3 | Main menu + messenger | 1 (menu) + service | 2 | M | 2 |
| 4 | Balance calculator | helpers → service | 1 | M | 3 |
| 5 | Session management seam | (inline, no extraction) | 0 | L | 3 |
| 6 | Open shift | 1 (open shift) | 1 | M | 4 |
| 7 | Expense flow | 3 (start, select cat, confirm) | 1 | M | 6 |
| 8 | Cash-in flow (A1) | 2 (start, confirm) | 1 | M | 6 |
| 9 | Exchange flow | 4 (start, in-cur, out-cur, confirm) | 1 | M | 6 |
| 10 | Close shift (B1, B2) | 5 (start, count, confirm, photo, notify) | 2 | H | 6 |
| 11 | Payment flow (A2) | **DEFERRED** | 0 | — | A2 fix |
| 12 | Collapse router | (structural) | 1 | L | 10 |
| 13 | Lint baseline | (audit) | 1 | L | 12 |

**Total commits:** ~16 (excluding A2 deferred)  
**Total estimated effort:** 6–8 days (extraction) + code review + testing  
**Deployment strategy:** Each commit is independently deployable. Phase 0–6 are low-risk enough to deploy mid-phase. Phases 7–10 should be batched and tested as a unit before deployment (money operations).

---

## 4. Target structure (after refactor)

Directory layout:

```
app/
  Actions/
    CashierBot/
      Handlers/
        HandleAuthAction.php
        ShowMainMenuAction.php
        ShowBalanceAction.php
        ShowMyTransactionsAction.php
        ShowGuideAction.php
        OpenShiftAction.php
        StartExpenseAction.php
        SelectExpenseCategoryAction.php
        ConfirmExpenseAction.php
        StartCashInAction.php
        ConfirmCashInAction.php
        StartExchangeAction.php
        SelectExchangeInCurrencyAction.php
        SelectExchangeOutCurrencyAction.php
        ConfirmExchangeAction.php
        StartCloseShiftAction.php
        HandleShiftCountInputAction.php
        ShowCloseConfirmAction.php
        HandleShiftPhotoAction.php
        ConfirmCloseShiftAction.php
        SendShiftCloseNotificationsAction.php
        (Payment flow Actions deferred — see A2)
  Services/
    CashierMessenger.php            (NEW: reply transport wrapper)
    CashierBalanceCalculator.php    (NEW: shift + balance helpers)
    CashierShiftService.php         (existing, enhanced if needed)
    CashierExpenseService.php       (existing, unchanged)
    CashierExchangeService.php      (existing, unchanged)
    BotPaymentService.php           (existing, contains A2 blocker)
    (other services remain unchanged)
  Http/
    Controllers/
      CashierBotController.php      (thin router, ~200 LOC)
        └─ methods: handleWebhook, processUpdate, handleMessage, handleCallback, handleState
        └─ helpers: menuKb, phoneKb, getPaymentData (if needed), aCb, alertOwnerOnError
        └─ (no extracted intent handlers)
```

**Router responsibilities (post-extraction):**

1. **Telegram envelope I/O** — `handleWebhook()`, `processUpdate()` remain the entry point
2. **Message dispatch** — `handleMessage()` routes on intent (commands, text, contact, photo)
3. **Callback dispatch** — `handleCallback()` matches `callback_data` → Action invocation
4. **State dispatch** — `handleState()` matches `$session->state` → Action invocation
5. **Idempotency claim** — pre-Action check for financial confirm actions (line 213–227)
6. **Error handling** — catch `\Throwable` and `alertOwnerOnError()`
7. **Session bootstrap** — auth check, session lookup, expiry logic

**Action responsibilities:**

1. Single public `execute(...)` method
2. Constructor-injected dependencies (services, messenger)
3. Business logic (shift lookup, balance calc, state mutation, service calls)
4. Reply message construction
5. Send reply via injected `CashierMessenger`
6. Return `void` (or optionally return status for testing)

**Shared utilities staying on controller:**

- `menuKb()` — keyboard formatting (tightly coupled to shift lookup)
- `phoneKb()` — keyboard formatting
- `aCb()` — **move to CashierMessenger** (see Phase 3 decision)
- `alertOwnerOnError()` — error handling (stays on controller, called by error handler)

---

## 5. Seams and known traps

### Trap 1: Session state mutations scattered

**Current:** `$session->update(['state'=>..., 'data'=>...])` called ~40 times across controller + actions.

**Proposed handling:** Keep state mutations **inline in each Action** (Phase 5 decision). Each Action owns its state transition within its domain.

**Rationale:** Centralizing state mutations into a `SessionStateMachine` service is a future optimization, not needed for Phase 1. Linear state flows (e.g., payment → payment_room → payment_amount) don't justify the extra abstraction right now.

**Risk:** State machine bugs if mutations are forgotten or called out of order. Mitigated by: (a) golden-master tests verify state transitions, (b) per-action unit tests, (c) explicit state transition docs in each Action.

**Future optimization:** If state bugs increase, extract `SessionStateMachine::transition($session, 'new_state', $data)` service post-extraction.

---

### Trap 2: Cross-bot coupling (expense approval)

**Current:** `CashierBotController::handleCallback()` (line 206–209) fan-outs `approve_expense_N` / `reject_expense_N` callbacks to `OwnerBotController::handleExpenseAction()`.

**Proposed handling:** Keep the fan-out in `CashierBotController::handleCallback()` (before Intent dispatch). The router recognizes expense approval callbacks and delegates to Owner bot.

```php
// in handleCallback(), line 206-209 stays as-is
if (preg_match('/^(approve|reject)_expense_(\d+)$/', $data, $matches)) {
    return app(\App\Http\Controllers\OwnerBotController::class)
        ->handleExpenseAction($chatId, $cb['message']['message_id'] ?? null, $callbackId, $matches[1], (int)$matches[2]);
}
```

This is a **pre-extraction check** and remains on the router (not extracted to Actions).

**Rationale:** Expense approval is a cross-bot concern (Cashier bot receives confirm, Owner bot receives approval). The router owns cross-bot routing, not individual Actions.

**Risk:** If `OwnerBotController::handleExpenseAction()` is refactored, this call site must be updated. Mitigated by: (a) this fan-out is well-documented (seam), (b) OwnerBotController is out-of-scope for this refactor.

---

### Trap 3: Message type dispatch (text vs photo vs contact)

**Current:** `handleMessage()` branches on message type (line 154, 162):
```php
if ($contact) return $this->handleAuth(...);
if ($photo && $session->state === 'shift_close_photo') return $this->handleShiftPhoto(...);
if ($text === '/start') return $this->showMainMenu(...);
if ($text === '/logout') return $this->logout(...);
return $this->handleState($session, $text);
```

**Proposed handling:** Keep message-type branching in `handleMessage()` router (not extracted). Photo dispatch check stays (line 154). Text-based state dispatch goes to `handleState()` which is extracted per intent.

**Rationale:** Message-type dispatch is router logic (envelope routing), not business logic. Each message type needs special handling (contact → user lookup, photo → file storage, text → state machine). Extracting would require each Action to know about message types (coupling).

**Risk:** None — this is the correct seam.

---

### Trap 4: Idempotency guard seam (callback → Action)

**Current:** Idempotency check happens in router (line 213–227) **before** Action invocation:
```php
if (in_array($data, self::IDEMPOTENT_ACTIONS, true) && $callbackId) {
    $claimResult = $this->claimCallback($callbackId, $chatId, $data);
    if ($claimResult !== 'claimed') {
        $this->send($chatId, "Operation already processed");
        return response('OK');
    }
}
```

Then Action calls `$this->succeedCallback($callbackId)` at the end (on success) or `$this->failCallback($callbackId, $error)` (on error).

**Proposed handling (A1 precedent):**  
- `claimCallback()`, `succeedCallback()`, `failCallback()` stay on router (pre-Action + post-Action)
- Action receives `$callbackId` as parameter: `execute(..., string $callbackId = '')`
- Action calls `$messenger->succeedCallback($callbackId)` on success (move to messenger service)
- Action throws exception on error; router catches + calls `failCallback()`

**Rationale:** Idempotency is a **transport concern** (Telegram callback is either processed once or failed/retried). It shouldn't live in Action business logic. The router owns this seam: claim before, succeed after, fail on exception.

**Risk:** If Action forgets to call `succeedCallback()`, the callback remains in 'processing' state and retries. Mitigated by: (a) unit test per Action verifies `succeedCallback()` is called, (b) explicit docstring on Action signature.

**Alternative:** Pass entire `TelegramProcessedCallback` state machine into Action. Rejected — too much coupling.

---

### Trap 5: `send()` coupling (Action → Controller)

**Current:** Every handler calls `$this->send($chatId, $text, $kb)` to reply.

**Proposed handling:** Extract `send()` to `CashierMessenger` service (Phase 3 decision).

```php
// in Action
$this->messenger->send($chatId, $text, $kb);
```

Constructor-inject `CashierMessenger` into each Action that sends replies.

**Rationale:** Actions should not depend on the controller. `send()` is a transport wrapper (Telegram reply sending), not controller logic.

**Risk:** If `CashierMessenger::send()` fails silently (wrapped in try-catch), Actions don't know. Mitigated by: (a) Messenger logs all failures (per current code, line 1730), (b) alerts owner on critical failures.

---

### Trap 6: Balance calculation (shared helper)

**Current:** `getShift()`, `getBal()`, `fmtBal()` are called by 10+ intents.

**Proposed handling:** Extract to `CashierBalanceCalculator` service (Phase 4 decision). Each Action that needs balance inject the calculator.

**Rationale:** Balance logic is reusable across Actions (shift lookup, transaction summing, format). Moving to a service makes it testable + decoupled.

**Risk:** If balance calculation has bugs, all Actions are affected. Mitigated by: (a) unit tests on calculator, (b) golden-master tests cover all balance-checking paths.

---

### Trap 7: Photo upload timing (state machine race)

**Current:** Photo is handled in `handleMessage()` as a state check (line 154):
```php
if ($photo && $session->state === 'shift_close_photo') return $this->handleShiftPhoto($session, $chatId, $photo);
```

But `showCloseConfirm()` says "send photo" **after** count input. So the state is `shift_close_photo` only during a brief window. If a photo arrives before state is set, it's ignored. If a photo arrives after confirm, it's ignored.

**Proposed handling:** Keep photo dispatch in `handleMessage()` pre-check (no extraction risk). Extract photo handler to Action, but invocation stays in router.

**Rationale:** This is correct — photo handler is special-cased in the message router because it's tied to a specific state + message type. No other intent has a photo handler.

**Risk:** None — this is the correct seam.

---

### Trap 8: A2 blocker (Manager FX override)

**Current:** `BotPaymentService::recordPayment()` throws `ManagerApprovalRequiredException` if override_tier is 'manager' (line 140–143).

**Proposed handling:** **Do NOT extract payment flow in this refactor.** Leave it inline on the controller. Document A2 blocker explicitly.

**Rationale:** Extracting payment before A2 is fixed risks breaking production. A2 is a behaviour change (adding approval flow), not a structural refactor. Defer payment extraction until A2 is resolved.

**Risk:** H — payment flow remains in the controller (1,865 → 1,332 LOC after extraction, still fat). Mitigated by: extracting payment in a separate A2 ticket post-fix.

**Timeline:** Phase 11 (payment extraction) is explicitly deferred. Unblock by implementing A2 manager approval flow (separate ticket).

---

## 6. Deferred / out-of-scope explicit list

Intentionally NOT included in this refactor:

- ❌ **A2 FX approval behaviour change** — Manager tier override approval flow (blocked, separate ticket)
- ❌ **Payment flow extraction** — deferred until A2 is fixed
- ❌ **POS controller / `TelegramPosController` changes** — only Cashier bot extracted
- ❌ **Schema changes** — no migrations in this refactor (B1–B3 are already deployed)
- ❌ **Beds24 client changes** — external API calls remain in services
- ❌ **OwnerBotController changes** — only the expense approval fan-out is touched (pre-existing)
- ❌ **New features** — pure extraction, zero new UX
- ❌ **Russian UX copy / button labels** — zero changes allowed; behaviour must be byte-identical

---

## 7. Verification and parity strategy

**Golden-master approach:** 

We have no pre-extraction golden-master test for Cashier bot (unlike `ProcessBookingMessage`). Cashier bot testing relies on:
1. Three feature tests: `CashInIdempotency`, `OpenShiftSingleton`, `BeginningSaldoUniqueness` (core invariants)
2. Full bot test suite: `CashierBotSecurityTest`, `CashierBotWorkflowTest`, `GroupPaymentIntegrationTest`, etc.
3. Manual smoke tests on staging

**Parity bar:**  
- Phase 0: Create synthetic golden-master fixtures (one per intent type)
- After each phase: run full suite (golden master + feature + unit tests)
- All pre-extraction tests must pass green post-extraction
- Zero new test skips allowed
- Zero behaviour changes allowed

**Arch-lint baseline:**  
- Current baseline: `app/Http/Controllers/CashierBotController.php:74:fat-controller` (1 violation)
- Target baseline: `fat-controller` violation removed (router is ≤200 LOC)
- After Phase 12: run `scripts/arch-lint.sh` to confirm violation is gone
- Phase 13: regenerate baseline if needed

---

## 8. Risks and non-goals

| Risk | Mitigation |
|---|---|
| Session state bugs when intent logic moves | Per-action unit tests; golden-master verifies state transitions; state mutations stay inline per action |
| Role-based gates (inline `hasAnyRole` checks) | Preserve role checks exactly as-is in each extracted Action; do not move to middleware |
| Logging seams (Laravel `Log` facade calls) | Keep logging as-is in Actions; use same channels + context keys as current code |
| Russian button labels / UX copy | Zero changes allowed; copy every Telegram message string verbatim |
| Concurrent shift open (B2 race) | B2 guard (transaction + row lock) is already deployed; stays inline in `OpenShiftAction` |
| A1 idempotency guard | A1 guard is already deployed; stays in router pre-check + `ConfirmCashInAction` |
| FX variance blocking (A2) | A2 is explicitly deferred; payment flow extraction blocked until approval flow is implemented |
| Beds24 API failures | Fallback logic (manual room entry) stays inline; no changes to fallback behaviour |

---

## 9. Timeline / sequencing

Estimate in terms of atomic commits per phase + cumulative effort:

```
Phase 0 (plan):         1 commit,  ~1 day   — golden master + baseline
Phase 1–2 (read-only):  2 commits, ~1 day   — 3 actions (balance, txns, guide, auth)
Phase 3 (messenger):    2 commits, ~1 day   — CashierMessenger + ShowMainMenuAction
Phase 4 (calculator):   1 commit,  ~1 day   — CashierBalanceCalculator
Phase 5 (seam docs):    0 commits, ~0 days  — design docs (session mutations inline)
Phase 6 (open shift):   1 commit,  ~1 day   — OpenShiftAction (with B1+B2 guards)
Phase 7 (expense):      1 commit,  ~1 day   — 3 expense actions
Phase 8 (cash-in):      1 commit,  ~1 day   — 2 actions (with A1 guard)
Phase 9 (exchange):     1 commit,  ~1 day   — 4 actions
Phase 10 (close shift): 2 commits, ~2 days  — 6 actions + notifications service (complex)
Phase 11 (payment A2):  0 commits, deferred — blocked until A2 is fixed
Phase 12 (collapse):    1 commit,  ~0.5 day — thin router
Phase 13 (lint):        1 commit,  ~0.5 day — baseline refresh

Total: ~16 commits, ~10 days focused work
Can be done in parallel batches: (0) + (1-5) + (6-10) + (12-13)
Deployment: each phase independently, or batch 6-10 (money ops) for pre-prod testing
```

---

## 10. Success criteria

- ✅ `wc -l app/Http/Controllers/CashierBotController.php` → **≤ 250 LOC** (from 1,865; target is ~200)
- ✅ 20+ new files under `app/Actions/CashierBot/Handlers/` + 2 new services, each ≤ 200 LOC
- ✅ Golden-master test suite in `tests/Feature/CashierBot/` covers all non-A2 intents, all green
- ✅ All pre-extraction tests (feature + unit) pass without modification
- ✅ `scripts/arch-lint.sh` reports zero `fat-controller` violations on `CashierBotController`
- ✅ Payment flow left intact; A2 blocker documented explicitly
- ✅ `@cashier_bot` behaviour unchanged — same replies for same inputs (byte-identical except logging/tracing)
- ✅ Zero production incidents during or after rollout

---

## Appendix A: What to verify while reading the code (before start)

- ✅ Current exact LOC: **1,865** (confirmed via `wc -l`)
- ✅ Callback dispatcher (`handleCallback()` match, line 232–259): 27 callback_data patterns enumerated
- ✅ State dispatcher (`handleState()` match, line 335–351): 10 state patterns enumerated
- ✅ Constructor injections: 10 services + properties (confirmed)
- ✅ Session mutations: ~40 calls to `$session->update([...])` scattered across handlers
- ✅ `send()` call sites: every intent calls `$this->send()` for replies (59-line method)
- ✅ IDEMPOTENT_ACTIONS: 5 confirmed entries (payment, expense, exchange, close, cash_in)

---

## Appendix B: Open questions / decisions required

1. **Messenger service vs. controller base class?** → Chosen: CashierMessenger service (Phase 3)
2. **Session state machine vs. inline mutations?** → Chosen: inline (Phase 5), future optimization
3. **When to extract payment?** → Deferred until A2 is fixed (A2 is separate ticket)
4. **Baseline regen or not?** → Yes, after Phase 12 (Phase 13)
5. **A2 approval flow responsibility?** → Out of scope; document blocker; owner bot changes needed

---

## Appendix C: Commit message template

Each extraction commit should follow this pattern:

```
refactor(cashier-bot): extract <ActionName>Action

Move <intent name> logic from CashierBotController to app/Actions/CashierBot/Handlers/<ActionName>Action.php.

- Extract <intent name> handler (~<LOC> LOC)
- Constructor-inject <service>, <service>, ...
- Return formatted reply string
- Preserve idempotency guard for financial actions
- Preserve state mutations inline per action

Zero behaviour change. All pre-extraction tests pass (golden-master + feature suite).

Fixes: #<ticket> (refactor milestone)
```

---

