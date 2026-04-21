# Cashier Bot Business Logic Brief

**Status:** as of commit f365219, 2026-04-21  
**Audience:** engineers evaluating A2 and any cashier/POS changes  
**Scope:** business-behavior audit — NOT a refactor plan

---

## 1. Bot Purpose

The Cashier Bot (`CashierBotController.php:1,833 LOC`) is a Telegram interface for hotel front-desk cashiers. It supports four core physical operations:
- **Collect guest payments** against Beds24 bookings with live exchange rate presentation
- **Record operating expenses** (food, supplies, repairs, etc.) with optional owner approval
- **Exchange cash between currencies** (e.g., guest gives USD, cashier gives UZS)
- **Manage cash drawer shifts** — open, record transactions, close with physical count and discrepancy tracking

**Who uses it:** Registered cashiers and administrative staff at the hotel.  
**What it is NOT:** A POS system (payments are recorded after-the-fact, not at checkout), a settlement system (no auto-reconciliation), or a guest-facing tool (Telegram is internal-only).

---

## 2. Flows

### 1. Authentication / Session Bootstrap

**Entry point(s):** `POST /api/telegram/cashier/webhook` → `handleWebhook()` → `handleMessage()` with `contact` field (CashierBotController.php:74–176)

**Current behavior:**
1. Cashier sends phone number via Telegram contact button (`handleAuth()`, line 162)
2. Phone suffix (last 9 digits) matched against `users.phone_number` via LIKE query (line 165)
3. On match: `TelegramPosSession` upserted with `user_id`, `state='main_menu'` (line 167)
4. Session timeout: idle states (`main_menu`, `idle`, `null`) expire after TTL; active workflows are NOT expired mid-flow (line 146–150)
5. Activity touch: every message updates `updated_at` via `updateActivity()` (line 151)

**Inferred intended behavior:**
- One cashier per chat session (1:1 TelegramPosSession:cashier during shift)
- Phone suffix match is permanent lookup — no re-confirmation per login
- Session TTL is a soft guard for UI cleanup, not a hard transaction boundary (confirmed by in-flight state check)

**Invariants that must hold:**
- Session `user_id` must exist in `users` table and have cashier/admin role
- Only one active shift per drawer at a time (enforced at `openShift()`, line 362–370)
- Session state must be a valid key in `handleState()` match statement (line 335–351)

**Mismatch classification:**
- **Acceptable current behavior:** Phone LIKE suffix match (9 digits). No confirmation, but intentional for speed (Russian phone numbers are uniform length)

**Money/state risk points:**
- If `users.phone_number` is corrupted (duplicate suffix), multiple users can auth to same session → wrong cashier delta; mitigated by unique User PK, but not prevented at DB level
- Session timeout expires mid-payment without rollback → stale session exception at confirm-time (line 910–912)

---

### 2. Open Shift

**Entry point(s):** `callback_data='open_shift'` → `openShift()` (CashierBotController.php:355–391)

**Current behavior:**
1. Check: no open shift for this user (line 357)
2. Acquire drawer: fetch first active `CashDrawer` (line 358)
3. Singleton check: prevent concurrent open shifts on same drawer (line 362–370)
4. Create `CashierShift`: `status='open'`, `opened_at=now()` (line 372)
5. Carry forward: query last closed shift's `ShiftHandover` on same drawer (line 375–376)
6. For each currency with prior `counted_*` > 0, create `BeginningSaldo` row linked to new shift (line 378–382)
7. Show balance including carried-forward saldo (line 385–389)

**Inferred intended behavior:**
- Drawer is shared resource — next cashier inherits prior cashier's physical count (via handover record)
- Beginning saldo represents "cash already in the drawer" — not earned by this shift
- Balance display confirms successful inheritance

**Invariants that must hold:**
- One open shift per drawer at any moment (enforced by singleton check)
- `ShiftHandover.counted_uzs/usd/eur` matches the last closed shift's physical count (enforced by `closeShift()`)
- `BeginningSaldo` rows exist only for non-zero carry-forward amounts
- Next shift cannot open until prior shift is closed

**Mismatch classification:**
- **Acceptable current behavior:** Drawer is a singleton; if drawer is deleted, new shifts cannot open (by design)

**Money/state risk points:**
- If multiple concurrent requests try to open shift on same drawer, singleton check runs WITHOUT transaction lock → both inserts may succeed if timing is tight. Mitigation: Telegram single-threaded delivery per chat, but not enforced at DB layer
- Carry-forward uses `latest('id')` on `ShiftHandover` (line 376), which is not guaranteed to be the most recently closed shift if shifts are reordered; safer: `latest('created_at')` or FK link

---

### 3. Record Payment (Beds24 guest → FX presentation → confirm → CashTransaction)

**Entry point(s):** `callback_data='payment'` → `startPayment()` → `selectGuest()` → FX flow → `confirmPayment()` (lines 395–927)

**Current behavior:**

**Phase A: Guest selection (lines 395–585)**
1. Fetch in-house guests live from Beds24 API (arrivals today only; line 437–449)
2. On API failure, fall back to manual room ID entry (line 450–454)
3. Cashier selects guest or types room ID (lines 499–496)
4. Extract snapshot from live Beds24 payload: guest name, outstanding amount, currency regex on `rateDescription` (lines 1442–1479)
5. Import booking on-demand if missing from local DB (lines 1495–1570): create `Beds24Booking` row from live data using `updateOrCreate` (idempotent with webhook path)
6. Merge: live snapshot > local DB > defaults (lines 524–528)

**Phase B: FX presentation (lines 554–584)**
1. Call `BotPaymentService.preparePayment()`: resolve group-aware USD, ensure fresh FX sync, return frozen `PaymentPresentation` DTO (lines 70–94)
2. If booking is missing OR sync unavailable → hard block "FX rates unavailable, contact manager" (lines 581–584)
3. Show FX-indexed currency buttons (UZS/EUR/RUB) with pre-computed amounts (lines 563–569)

**Phase C: Amount entry (lines 602–674)**
1. Cashier selects currency (lines 602–639)
2. For FX path: show presented amount in selected currency + inline confirm button (lines 614–622)
3. Cashier either:
   - Clicks ✅ to accept presented amount as-is → override_tier = None (line 723–735)
   - Enters custom amount → evaluate variance vs. presented (lines 741–774)
4. Variance evaluation via `FxOverridePolicyEvaluator.evaluate()`:
   - None (≤ 0.5%): proceed to payment method
   - Cashier (0.5–2%): ask for override reason (lines 770, 779–792)
   - Manager (2–10%): block with escalation message (lines 772, 798–814)
   - Blocked (> 10%): hard reject (line 772, 798–814)

**Phase D: Confirm & record (lines 876–927)**
1. Create `RecordPaymentData` DTO with frozen `PaymentPresentation` (lines 889–897)
2. Call `BotPaymentService.recordPayment()` (line 898–908):
   - Check presentation not expired (> 20 min; line 122–126)
   - Validate override tier again (line 131–144)
   - Lock booking row under transaction (line 158–166)
   - Guard against duplicate payment (standalone + group tiers; line 170)
   - Record `CashTransaction` with full audit superset: FX snapshot, override traceability, group metadata (lines 183–232)
   - Create `Beds24PaymentSyncJob` for async push (lines 235–250)
3. On success: update callback status to 'succeeded', show balance (line 908–909)
4. On known exception: set callback to 'failed' (idempotency allows retry), show user-facing error (lines 910–924)

**Inferred intended behavior:**
- Frozen FX snapshot prevents race conditions — rate cannot change mid-flow
- Presented amount is "form amount" from Beds24 sync; variance is realistic (USD→local currency exchange rounding)
- Manager tier requires async approval via OwnerBotController (NOT implemented here; see mismatch)
- Group payments: one USD amount split across multiple rooms; master booking ID prevents duplicate group collection

**Invariants that must hold:**
- `PaymentPresentation` must be valid for ≤ 20 minutes (`TTL_MINUTES`)
- `Beds24Booking.isPayable()` check prevents cancelled bookings from being charged
- Exchange rate snapshot (UZS/EUR/RUB presented amounts) is immutable once frozen in DTO
- One CashierShift per cashier user; shift must be open to record payment
- No duplicate cashier-bot payments for same booking ID (checked under booking row lock)
- Group payments: all sibling rooms in a group share one master booking ID and prevent concurrent collection

**Mismatch classification:**
- **KNOWN A2 BUG — manager approval asymmetry** (see section 5): Manager tier override in payment requires async approval, but code at line 140–143 throws `ManagerApprovalRequiredException` before asking the manager. No approval flow exists in CashierBotController; must be routed through OwnerBotController (NOT YET IMPLEMENTED). Currently blocks payment with no resolution path.
- **Missing feature:** Manual payment fallback (Microphase 7): removed hard. If FX sync unavailable, operator must contact manager offline; no bypass. Intentional defense-in-depth.
- **Missing feature:** Payment reversal / refund path. If guest overpaid, no in-bot reversal; must be handled offline via manual adjustment.

**Money/state risk points:**
- Presentation expires silently after 20 min of inactivity → if workflow stalls mid-conversation, cashier must re-start payment (no money recorded if error, so safe, but UX poor)
- `preparePayment()` auto-re-syncs if group amount differs from stored sync (lines 84–92): if sibling booking was added to group after first sibling was synced, re-sync may increase USD total, changing presented amounts. This is correct but could surprise cashier if dialog was already shown.
- Beds24 API failures in live guest fetch (line 450) fall back to manual entry silently — no explicit notification to cashier that API was down (only logged)
- Manager approval: if manager is offline, `ManagerApprovalRequiredException` blocks payment with no recovery path → manual offline resolution required

---

### 4. FX Exchange (paired in/out transactions)

**Entry point(s):** `callback_data='exchange'` → `startExchange()` → currency pair + amounts → `confirmExchange()` (lines 1320–1428)

**Current behavior:**
1. Cashier selects in-currency (what they receive from guest, e.g., USD) (lines 1327–1336)
2. Enter in-amount (line 1347–1366)
3. Select out-currency (what they give to guest, e.g., UZS), excluding in-currency (lines 1369–1376)
4. Enter out-amount (lines 1378–1403)
5. Show confirmation with approximate rate (lines 1386–1394: calculates implied UZS/foreign exchange rate for display only)
6. Call `CashierExchangeService.recordExchange()` inside DB::transaction (lines 1416):
   - Lock shift, validate still open (line 32–34)
   - Create TWO `CashTransaction` rows atomically (in + out, sharing `reference` and marked as paired via `related_amount` + `related_currency`; lines 38–67)
   - Succeed callback inside transaction boundary (line 69–70)
7. Show balance after exchange (line 1419)

**Inferred intended behavior:**
- Exchange is a net-neutral cash operation: money in one currency, money out another, net balance flat in mixed-currency accounting
- Implied rate is shown for informational purposes only (help cashier verify they didn't fat-finger)
- Paired transactions allow reconstruction of who traded what with whom

**Invariants that must hold:**
- Two `CashTransaction` rows per exchange with matching `reference` (e.g., "EX-20260321143022")
- In-currency ≠ out-currency (enforced at UI button generation, line 1358)
- Both amounts > 0 (validated before confirm, lines 1350, 1381)
- Shift must be open (validated under lock, line 33)
- One open shift per user at confirm time

**Mismatch classification:**
- **Acceptable current behavior:** No validation of exchange rate realism. Bot does not prevent 1 USD = 1 UZS swaps (clearly wrong, but rate is display-only; human error is not prevented)

**Money/state risk points:**
- Implied rate is calculated client-side and shown for UX only — bot does NOT enforce rate realism
- If shift closes mid-exchange, transaction is rolled back (good) but Telegram callback is not un-succeeded → retry is blocked until callback expires (minor UX issue, timeout is implicit)
- Exchange is manual cashier entry; no sync to Beds24 (Beds24 has no exchange concept)

---

### 5. FX Override Policy (tiers None/Cashier/Manager/Blocked)

**Entry point(s):** `FxOverridePolicyEvaluator.evaluate()` called during payment amount entry (CashierBotController.php:761) and again at confirm-time (BotPaymentService.php:131)

**Current behavior:**
1. Config-driven thresholds (Services/Fx/OverridePolicyEvaluator.php:15–26):
   - Tolerance: 0.5% (rounding/market movement acceptable)
   - Cashier threshold: 2% (cashier can self-approve with reason)
   - Manager threshold: 10% (requires manager approval)
   - Above 10%: Blocked (hard reject)
2. Variance calculation: `|(proposed − presented) / presented| × 100` (line 41–43)
3. Tier assignment logic (line 59–75):
   - Within tolerance → None
   - tolerance < variance ≤ cashier_threshold → Cashier
   - cashier_threshold < variance ≤ manager_threshold → Manager
   - variance > manager_threshold → Blocked

**Comparison to symmetric approval (expense approval pattern in OwnerBotController):**

| Flow | Variance triggered | Approval path | Async requirement |
|------|-------------------|-----------------|-----------------|
| Payment (Cashier tier) | 0.5–2% | Cashier types reason inline; proceeds immediately | No |
| Payment (Manager tier) | 2–10% | **BLOCKED** — throws ManagerApprovalRequiredException, no resolution path | YES (NOT IMPLEMENTED) |
| Expense | > 500k UZS / $40 / €35 | Recorded immediately; async owner approval via OwnerBotController callback | YES (implemented) |

**Mismatch:** Payment manager approval is ASYMMETRIC:
- Expense approval: created optimistically, owner approves/rejects async (forward path + rollback path)
- Payment approval: blocked at entry, no forward path (manager never sees the request)

**Current behavior at blockFxOverride (line 798–814):**
- Shows user message: "Escalate question to management offline"
- Returns to main menu (NO state saved, workflow abandoned)
- Money is NOT recorded
- Callback is NOT succeeded/failed (left in 'processing' until TTL expires, blocking retry)

**Inferred intended behavior:**
- Manager tier should trigger async approval similar to expense flow
- Cashier should NOT have to abandon payment; should wait for manager approval message
- Blocked tier is absolute (no possible override) — correct behavior

**Invariants that must hold:**
- Tolerance < cashier_threshold < manager_threshold (config validation)
- Override evaluation runs TWICE: at amount-entry time (UI feedback) AND at confirm-time (prevention of race conditions)
- Variance calculation prevents division by zero (line 41: guarded with `presentedAmount > 0`)

**Mismatch classification:**
- **CONFIRMED A2 BUG — asymmetric approval:** Manager tier payment override has no approval path. Blocks flow, leaves callback hanging. See section 6.2 "Needs decision" for resolution.
- **Missing feature:** Manager approval request for payment (should mirror expense approval UX)
- **Missing feature:** Blocked tier grace period (once variance exceeds limit, no re-entry allowed, even if new rate becomes available)

**Money/state risk points:**
- Double evaluation (UI + confirm) is correct for race prevention, but if config changes between steps, cashier sees different tier at entry vs. confirm (low risk, but possible)
- Tolerance of 0.5% may be too tight for volatile FX rates (e.g., closing at end of day); no guidance in comments
- Manual override reasons are free-text, no validation → spam/empty reasons possible

---

### 6. Expense (flat / approval-threshold / owner approval round-trip)

**Entry point(s):** `callback_data='expense'` → `startExpense()` → category → amount → description → `confirmExpense()` (lines 931–1017)

**Current behavior:**
1. Select category from `ExpenseCategory` list; auto-create if empty (lines 935–943)
2. Enter amount + currency (smart parser; line 957–966)
3. Enter description (line 969–985)
4. Determine if approval needed: `amount > threshold` per currency (line 973–976):
   - UZS: config value, default 500,000 (≈ $5)
   - USD: $40
   - EUR: €35
5. Show confirmation; if `needs_approval=true`, add note "Requires owner approval" (line 978–979)
6. Call `CashierExpenseService.recordExpense()`:
   - Lock shift, validate open (lines 31–35)
   - Create `CashExpense` row with `requires_approval` flag (lines 38–47)
   - Create paired `CashTransaction` (type=out, reference="expense:{id}"; lines 49–60)
   - Succeed callback inside transaction (lines 62–64)
7. Outside transaction: if approval needed, call `OwnerBotController.sendApprovalRequest()` async (lines 1001–1007)
   - Send approval message to owner with ✅ approve / ❌ reject buttons
   - Owner callback routes back to `OwnerBotController.handleExpenseAction()` (CashierBotController.php:207–209)

**Owner approval flow (OwnerBotController.php:60–162):**
1. Receive callback: `approve_expense_N` or `reject_expense_N`
2. Validate: expense exists, not already approved/rejected, user is admin (lines 62–82)
3. If approve:
   - Update `CashExpense.approved_by`, `approved_at` (lines 87–90)
   - Edit original message to show ✅ ОДОБРЕНО (lines 95–96)
   - Notify cashier via `notifyCashier()` (line 99)
4. If reject:
   - Update `CashExpense.rejected_by`, `rejected_at` (lines 102–105)
   - Call `reverseExpenseTransaction()`: create reversal `CashTransaction` (type=in, amount back; lines 108, 125–162)
   - Edit original message to show ❌ ОТКЛОНЕНО (lines 112–113)
   - Notify cashier (line 116)

**Inferred intended behavior:**
- Expense is recorded optimistically; approval is async, non-blocking
- Rejection reverses money to shift balance (adds back as 'in' transaction)
- Thresholds are per-currency and represent "requires second opinion" boundary
- Owner can approve/reject for hours after expense was recorded (no time limit)

**Invariants that must hold:**
- `CashExpense` is created BEFORE owner approval (not waiting for it)
- `CashTransaction` created atomically with `CashExpense` (reference link via "expense:{id}")
- Only one `approved_at` or `rejected_at` per expense (mutually exclusive)
- Reversal transaction references original via "reversal:expense:{id}" (allows chain reconstruction)
- Notification fallback uses `TelegramPosSession.chat_id` (active bot session), then `User.telegram_user_id` (legacy fallback; line 175–179)

**Mismatch classification:**
- **Acceptable current behavior:** Approval threshold is high by design (catches only large expenses; small ones auto-pass)
- **Acceptable current behavior:** Free-text description, no validation (casual log)

**Money/state risk points:**
- Approval notification sent OUTSIDE transaction boundary (line 1001) → if notification fails, expense is already recorded (money is out, notification is non-critical, so acceptable)
- `reverseExpenseTransaction()` uses fuzzy match (reference, then amount+currency+notes; lines 130–137) as fallback if old rows lack FK reference. Fallback could match wrong transaction if duplicate expenses exist. Lower risk now that reference link is enforced, but no cleanup of old fallback paths.
- If owner rejects but shift is already closed, reversal adds money to closed shift (no UI feedback to cashier). Shift close sequence does not check for pending expense approvals.
- If cashier logs expense, then logs out, owner later rejects: cashier never sees reversal notification (if session expired)

---

### 7. Deposit / Cash In (admin-only; `confirmCashIn`)

**Entry point(s):** `callback_data='cash_in'` → `startCashIn()` → amount+currency → `confirmCashIn()` (lines 1019–1093)

**Current behavior:**
1. Auth check: only super_admin/admin/manager can access (line 1025–1027)
2. Enter amount + currency (smart parser; line 1033–1049)
3. Show confirmation (line 1041–1048)
4. On confirm (lines 1051–1093):
   - Re-check role (line 1061–1065)
   - Re-check shift open (line 1056–1060)
   - Validate amount > 0 (line 1068–1072)
   - Create `CashTransaction`: type=in, category=deposit, source_trigger=manual_admin (lines 1074–1084)
   - Succeed callback inside create() (line 1086)
5. Show balance (line 1087)

**Inferred intended behavior:**
- Cash in is for when admin manually adds cash to drawer (e.g., float from manager, supplier payment received)
- No approval needed (admin is trusted)
- Source is marked as 'manual_admin' to distinguish from guest payments and expenses

**Invariants that must hold:**
- User must have admin role (checked twice: startCashIn + confirmCashIn)
- Shift must be open
- Amount > 0
- Idempotency guarded by callback claim (line 213–227) — as of commit f365219

**Mismatch classification:**
- **Acceptable current behavior:** No validation of amount realism (admin could add $1M by mistake, but that's their responsibility)

**Money/state risk points:**
- Role check runs TWICE (lines 1025, 1061) — redundant but safe
- If role is revoked between startCashIn and confirmCashIn, second check catches it (good)
- Callback status update is inside `confirmCashIn()` transaction, but exception handling re-throws (line 1091) without updating callback to 'failed'. If exception thrown after callback is succeeded (unlikely), callback state would be stale.

---

### 8. Balance Calculation (`getBal`)

**Entry point(s):** `showBalance()` → `getBal($shift)` (CashierBotController.php:1097–1109, 1578–1598)

**Current behavior:**
1. Initialize balance dict: UZS=0, USD=0, EUR=0 (line 1580)
2. Add beginning saldos: query `BeginningSaldo` for this shift, sum by currency (lines 1583–1587)
3. Add transaction deltas: query `CashTransaction`, call `.drawerTruth()` scope (line 1590), sum by currency with type multiplier (lines 1593–1596):
   - type=in: add amount
   - type=out: subtract amount
   - type=in_out: skip (complex, handled separately — none currently used)

**`drawerTruth()` scope:** filters to drawer-relevant transactions, excludes `source_trigger='beds24_external'` audit rows (see CashTransaction model scope)

**Inferred intended behavior:**
- Balance is cumulative: prior shift's handed-over count + current shift's net transaction flow
- Excludes external audit rows (Beds24 auto-sync corrections) so it reflects actual drawer money, not booking adjustments
- Multi-currency tracking per shift

**Invariants that must hold:**
- `BeginningSaldo.amount` is non-negative (enforced at create-time by open-shift logic)
- Each `CashTransaction` has type in {in, out, in_out} and valid currency
- `drawerTruth()` scope is applied to filter out audit rows
- Balance is computed fresh on each call (no caching; reflects latest state)

**Mismatch classification:**
- **Acceptable current behavior:** No validation of balance realism. If balance goes negative, it's displayed (cashier expected to notice and report)

**Money/state risk points:**
- Balance calculation is O(N) in transaction count; for very long shifts (100+ transactions), could be slow. No pagination or summary query.
- If `BeginningSaldo` was corrupted (two rows for same currency), balance would double-count. No unique constraint on (shift_id, currency). **RISK: data integrity depends on application logic, not DB constraint.**

---

### 9. Close Shift (counts → ShiftHandover → EndSaldo with discrepancy; photo path)

**Entry point(s):** `callback_data='close_shift'` → `startClose()` → count_uzs → count_usd → count_eur → photo → `confirmClose()` (lines 1162–1250)

**Current behavior:**
1. Show expected balance (from `getBal()`; line 1169–1171)
2. Cashier counts each currency in sequence (lines 1174–1191):
   - UZS → USD → EUR (progression defined by `match($cur)` on line 1180)
   - After EUR, skip photo, go straight to confirm (line 1183)
3. Optionally: cashier sends drawer photo (lines 1214–1221)
4. Show close confirmation with discrepancy calc (lines 1193–1212):
   - For each currency, show expected vs. counted (line 1199–1205)
   - Color by discrepancy severity (line 1282: <1% green, 1–5% yellow, >5% red)
5. On confirm (lines 1223–1250):
   - Call `CashierShiftService.closeShift()` inside transaction:
     - Create `ShiftHandover`: counted_uzs/usd/eur (actual count), expected_uzs/usd/eur (calculated balance), photo_path (lines 48–57)
     - Update shift status → 'closed', closed_at=now() (line 59)
     - Create `EndSaldo` records for each currency (lines 62–76): discrepancy calc, reason if mismatch (line 72)
     - Succeed callback inside transaction (line 78–79)
   - Outside transaction: send owner notification with discrepancy report (lines 1237, 1255–1299)
   - If photo, send via owner-alert bot (lines 1305–1313)

**ShiftHandover** row captures handoff state for next shift:
- `counted_uzs/usd/eur`: what cashier physically counted (input)
- `expected_uzs/usd/eur`: what system expected (balance at close time)
- `hasDiscrepancy()`: returns true if any currency's count ≠ expected (used in notification severity calc; line 1271)

**EndSaldo** row records close-time saldo for audit:
- `expected_end_saldo`: expected balance
- `counted_end_saldo`: physical count
- `discrepancy`: counted − expected
- `discrepancy_reason`: "Via Telegram bot" if difference > 0.01 (line 72)

**Owner notification (outside transaction):**
- Severity emoji: 🟢 no discrepancy, 🟡 <5%, 🔴 >5% (line 1282–1283)
- Show all currency counts with differences (lines 1290–1294)
- If discrepancy >1%: warn owner
- Send photo as separate message (lines 1309–1311)

**Inferred intended behavior:**
- Shift close is final; creates audit trail (handover for next cashier, end-saldo for reconciliation)
- Photo is optional (UI does not require it) but encouraged (instructions suggest it; guide_close, line 1812)
- Discrepancy is expected and captured (rounding, counting error, pilferage — all tracked but not actioned by bot)
- Next shift carries forward counted amounts as beginning saldo (see flow 2)

**Invariants that must hold:**
- One `ShiftHandover` per closed shift
- `EndSaldo` created only for currencies with expected > 0 OR counted > 0 (line 65)
- Shift status must be 'open' at close-time (enforced under lock, line 42–44)
- Callback must be claimed before confirmClose() is called (idempotency, line 213–227)
- Photo ID is a Telegram file ID or null

**Mismatch classification:**
- **Acceptable current behavior:** No validation of count realism. Negative counts are technically allowed (line 1177) but caught by type checking (negative float input would fail)
- **Acceptable current behavior:** No requirement to explain large discrepancies (reason is auto-filled "Via Telegram bot"; no cashier justification)

**Money/state risk points:**
- Photo field stores Telegram `file_id`, which expires after 24 hours (Telegram doc). If owner doesn't download/save within 24h, photo is lost. **RISK: no persistence to local storage.**
- Handover calculation runs AFTER shift is locked (line 33) but BEFORE callback is succeeded (line 78). If callback failure occurs after handover creation, handover exists but shift is still 'open' (transaction rollback, so actually shift status is rolled back too — safe).
- Owner notification sent OUTSIDE transaction (line 1255) → if it fails, shift is already closed. Notification loss is non-critical but means owner doesn't see discrepancy alert (email/log as fallback).
- Discrepancy severity (line 1272–1280) uses floating-point percentage comparison (no epsilon guard) → rounding edge cases possible, but unlikely to affect integer counts

---

### 10. Owner Approval Interactions (approve/reject expense, `OwnerBotController`)

**Entry point(s):** Callback `approve_expense_N` or `reject_expense_N` routed from CashierBotController line 207–209 → `OwnerBotController.handleExpenseAction()`

**Current behavior:** (see flow 6 for integration)

**Approval path (approve):**
1. Expense found, not yet approved/rejected (lines 62–74)
2. Caller authenticated as admin (lines 76–82)
3. Update `CashExpense.approved_by`, `approved_at` (lines 87–90)
4. Edit owner bot message to show ✅ ОДОБРЕНО (lines 95–96)
5. Notify cashier: "✅ Ваш расход одобрен!" (lines 99, 183)

**Rejection path (reject):**
1. Expense found, not yet approved/rejected (lines 62–74)
2. Caller authenticated as admin (lines 76–82)
3. Update `CashExpense.rejected_by`, `rejected_at` (lines 102–105)
4. Call `reverseExpenseTransaction()`:
   - Find original transaction via reference "expense:{id}", fallback to fuzzy match (lines 130–137)
   - Create reversal transaction: type=in, reference="reversal:expense:{id}", category=other (lines 141–150)
   - Log result (line 152–154), or warn if transaction not found (line 155)
5. Edit owner bot message to show ❌ ОТКЛОНЕНО (lines 112–113)
6. Notify cashier: "❌ Ваш расход отклонён!" + "⚠️ Сумма возвращена в баланс смены" (lines 116, 190–192)

**Cashier notification (line 167–200):**
- Prefer active `TelegramPosSession.chat_id` (where cashier is now interacting) over `User.telegram_user_id` (fallback if offline)
- Send via cashier bot, not owner-alert bot (line 195–196)
- Gracefully fail if cashier has no session or telegram_user_id (line 180–181)

**Inferred intended behavior:**
- Approval/rejection is idempotent (second click on same button is rejected, lines 70–73)
- Reversal is automatic on rejection (cashier doesn't need to delete the expense manually)
- Cashier is notified in the cashier bot session, not owner bot
- Owner's message is edited in-place to show final decision

**Invariants that must hold:**
- `CashExpense.requires_approval` was true (not enforced here; assume caller only sends approval callbacks for expenses that needed it)
- `approved_by` and `rejected_at` are mutually exclusive (enforced by checking both are null at entry, line 70)
- Reversal transaction references original via FK (reference field; fallback to fuzzy match for legacy data)
- Cashier user must have active session or telegram_user_id to receive notification (graceful no-op if neither exists)

**Mismatch classification:**
- **Acceptable current behavior:** Fuzzy match fallback (lines 131–137) is defensive; newer code always sets reference FK, so fallback is rarely hit
- **Acceptable current behavior:** Reversal category is hardcoded as 'other' (not 'refund' or similar) — generic but acceptable

**Money/state risk points:**
- `reverseExpenseTransaction()` is called outside transaction boundary (lines 125–162). If it fails (transaction not found, create fails), exception is caught and logged (line 156–161), but expense is already marked rejected. Money is NOT reversed, but status is final. **RISK: rejected expense without reversal transaction → money is lost from recorded balance.**
- Cashier notification failure (line 197–199) is caught and logged (line 198) but does not prevent approval/rejection (graceful, but cashier may not learn of decision if offline)
- If cashier logs out before owner approves, notification has no active session to send to (falls back to telegram_user_id; if that's also stale, notification is lost silently)

---

### 11. Telegram Retry / Callback Idempotency Behavior

**Entry point(s):** `handleCallback()` → idempotency guard (lines 198–227)

**Callback claim lifecycle:**
1. Action is idempotent (in `IDEMPOTENT_ACTIONS` list, line 190–196):
   - confirm_payment, confirm_expense, confirm_exchange, confirm_close, confirm_cash_in
2. Call `claimCallback()` (line 214):
   - Check if callback_query_id exists in `telegram_processed_callbacks`:
     - If status='succeeded': return 'succeeded' → short-circuit, show "already processed" (line 221–223)
     - If status='processing': return 'processing' → short-circuit, show "in progress, wait" (line 221–223)
     - If status='failed': delete the failed row (line 281–284) to allow retry
   - Try INSERT UNIQUE on callback_query_id (line 289–295):
     - If INSERT succeeds: return 'claimed' (line 296) → proceed with action
     - If UNIQUE constraint violation (race): fetch row, check status (line 299–302), return status (succeeded/processing)
3. After action completes successfully: call `succeedCallback()` (line 308–315) → update status='succeeded'
4. After action fails: call `failCallback()` (line 321–331) → update status='failed' (allows retry)

**Retry behavior:**
- User clicks button again if previous was stuck/failed
- Claim check detects prior failure (status='failed') and deletes old row (line 281–284)
- Subsequent INSERT claims the callback again (idempotent re-attempt)
- If success: callback status changes to 'succeeded', subsequent retries short-circuit with "already processed"

**States:**
- 'processing': action in progress (DB lock held or external API call in flight)
- 'succeeded': action completed; money was recorded; further retries rejected
- 'failed': action failed; state was NOT recorded; retry is safe and encouraged

**Inferred intended behavior:**
- Telegram retries same callback for 30 sec after 500/timeout responses (API contract)
- First attempt claims callback and starts action
- Concurrent retry (during 'processing') is queued by Telegram or rejected by claim logic (return 'processing')
- Failed actions are retryable; succeeded actions are terminal

**Invariants that must hold:**
- `telegram_processed_callbacks.callback_query_id` is UNIQUE (enforced by DB schema)
- Status must be one of: pending (unused), processing, succeeded, failed
- `claimed_at` is set when callback is claimed (line 294)
- `completed_at` is set when status changes from processing to succeeded/failed (line 314, 329)
- No callback exists in 'pending' state (only in use: processing/succeeded/failed)

**Mismatch classification:**
- **Acceptable current behavior:** 'failed' rows are deleted and re-claimed (allows retry), but old row's error message is lost. No history of attempts; only latest error stored in update (line 328).

**Money/state risk points:**
- Race condition on claim (line 297–303): between check-exists and INSERT, another request inserts. Mitigation: UNIQUE constraint + exception handler (line 297), then re-fetch to determine if succeeded/processing. **SAFE: race is detected and handled.**
- Callback TTL: telegram doesn't tell us when callback expires (typically 24h). If callback is still in DB and user clicks it after TTL, old callback_query_id is reused (unlikely, Telegram usually generates new IDs). **LOW RISK: Telegram protocol handles ID uniqueness.**
- Status='failed' rows are kept until next retry attempt (line 281–284), which could consume storage for long-abandoned failed attempts. **LOW RISK: manual cleanup can purge old rows.**
- If action is in 'processing' state for > N minutes (process crashed, webhook handler died), callback is orphaned and blocks further attempts. **RISK: no timeout cleanup; manual intervention required. Mitigation: short-circuit reply at line 223 notifies user to wait.**

---

## 3. Cross-Cutting Concerns

### Role / Authorization

All financial actions are guarded by role checks:
- **Cash in (admin-only):** `hasAnyRole(['super_admin', 'admin', 'manager'])` (line 1025, 1061)
- **Expense approval (owner-only):** `hasAnyRole(['super_admin', 'admin', 'manager'])` (OwnerBotController line 79)
- **Cashier (any):** no explicit role check; all users in `users` table who match phone number can open shift and record transactions

**Risk:** No enforcement that user has 'cashier' role. Only positive checks for admin actions. If user is deleted/suspended, their session remains valid until logout (line 154–158).

### Session Timeout vs. Workflow Integrity

Session timeout is soft (line 146–150): only applied to idle states, not in-flight workflows.
- **Idle states:** main_menu, idle, null → expire after TTL (default 1h from `updated_at`)
- **Active states:** any payment/expense/exchange state → NOT expired mid-flow
- **Touch:** every message updates `updated_at` (line 151)

**Risk:** If cashier is AFK during payment (e.g., left phone on desk), session is held open indefinitely if they periodically touch it. No absolute time limit per shift; only activity-based limit per session.

**Mitigation:** Workflow timeout is enforced separately — `PaymentPresentation` expires after 20 minutes (line 122–126), not session timeout.

### External API Failures

**Beds24 guest fetch (line 437–449):**
- Silently returns empty array on exception (line 450–454)
- Fallback: manual room ID entry
- **Logged as warning** but not user-facing until they try to proceed

**Beds24 live booking lookup (line 474–493):**
- Catches exception, shows user "not found" message (line 490–491)
- **Logged as warning**

**FX rate service (line 646–675):**
- Falls back to manual rate entry if all sources fail (line 666–674)
- **Not logged** at WARNING level; user sees "⚠️ Could not get rate automatically"

**Telegram send failures (line 1687–1700):**
- Caught, logged at WARNING level (line 1694–1695)
- **User sees no response** (HTTP 200 OK is sent anyway)

**Notification failures (approval, close report, cash-in validation):**
- All caught, logged, and gracefully skipped (lines 1004–1006, 1314–1316, etc.)
- **Money is recorded even if notification fails**

### Error Notification Path

**Function:** `alertOwnerOnError()` (line 1713–1724)
- Called on unhandled exception in payment, expense, exchange, close workflows (line 922, 1012, 1423, 1243)
- Sends message to owner: context, cashier name, error message (first 200 chars), file:line
- Uses `OwnerAlertService.sendShiftCloseReport()` (same channel as close notification)

**Risk:** If OwnerAlertService is down, owner doesn't learn of errors. No fallback notification (email, log, etc.).

---

## 4. Data Model Invariants (Global)

### Shift & Drawer
- **One open shift per drawer at a time** (enforced by singleton check at line 362–370; NOT enforced at DB level via unique constraint)
- **Next shift begins with prior shift's handover** (FK link: BeginningSaldo → ShiftHandover via carry-forward at line 375–382)
- **Shift close is final** (status → 'closed', closed_at set; cannot reopen without manual intervention)

### Transactions
- **All transactions are linked to one shift** (cashier_shift_id is FK)
- **Type is in {in, out, in_out}** (Enum: TransactionType)
- **Currency is in {UZS, USD, EUR, RUB}** (Enum: Currency; though RUB is only used in FX presentation, not recorded)
- **Exchange produces exactly 2 rows sharing a reference** (EX-YYYYMMDDHHMSS; paired via reference field)
- **Expense produces exactly 2 rows sharing a reference** (reference="expense:{id}", with paired CashTransaction)

### FX & Payment
- **PaymentPresentation is frozen at prepare-time** (DTO immutable; rates never re-fetched during flow)
- **Presented amounts are computed once, cached in DTO** (uzsPresented, eurPresented, etc.; fixed at preparePayment line 94)
- **Manager approval for payment is required but has no resolver** (BUG A2: exception thrown, no approval flow exists)
- **One cashier-bot payment per booking** (guarded at line 279–287; DuplicatePaymentException on second attempt)
- **Group payments prevent concurrent collection of sibling rooms** (guarded at line 291–303; DuplicateGroupPaymentException)

### Approval & Reversal
- **Expense is created BEFORE owner approves** (optimistic recording; no wait)
- **Rejection triggers reversal transaction** (type=in, reference="reversal:expense:{id}")
- **Reversal is created outside transaction boundary** (lines 125–162; risk: reversal may fail while expense is marked rejected)
- **Approved/rejected are mutually exclusive** (lines 70–73; enforced by application, not DB constraint)

### Balance & Saldo
- **Beginning saldo is carried from prior shift's handover** (FK: BeginningSaldo → ShiftHandover)
- **Handover contains expected vs. counted amounts** (system calc vs. cashier count; discrepancy is recorded but not actioned)
- **EndSaldo contains final expected vs. counted** (created atomically with shift close; discrepancy is purely informational)
- **Current balance = beginning saldo + net transactions** (calculated fresh each call; no caching)

---

## 5. Summary Matrix

| Flow | Risk Level | Classification | Summary |
|------|-----------|-----------------|---------|
| Auth / session | L | Acceptable | Phone suffix match; session TTL soft-enforced on idle only |
| Open shift | M | Acceptable | Drawer singleton enforced at app layer, not DB; carry-forward correct |
| Record payment | **H** | **Confirmed bugs + missing features** | Manager approval has no resolver (A2 BUG); manual fallback removed; session expiry blocks mid-flow |
| FX exchange | L | Acceptable | Manual rate entry; no rate validation; paired transactions correct |
| FX override policy | **H** | **Confirmed bug** | Manager tier blocks payment with no approval path (A2 BUG); asymmetric vs. expense approval |
| Expense | M | Acceptable | Optimistic recording; approval async; reversal risk if reversal tx fails |
| Deposit/cash-in | L | Acceptable | Admin-only; no approval; idempotency guarded |
| Balance calculation | M | Acceptable | Fresh calculation; multi-currency correct; no caching; O(N) performance risk on long shifts |
| Close shift | M | Acceptable | Discrepancy captured; photo is temporary (24h TTL); owner notification outside transaction |
| Owner approval | M | Acceptable | Reversal created outside transaction (risk: reversal may fail); notification graceful failure |
| Callback idempotency | L | Acceptable | UNIQUE constraint + exception handler safe; failed rows cleaned on retry; orphaned 'processing' rows possible |

---

## 6. Three Buckets

### 6.1 Fix Now

1. **A2 BUG: Manager FX override approval has no resolver**
   - **Issue:** Manager tier variance (2–10%) throws `ManagerApprovalRequiredException` at line 140–143, blocking payment with no path to approval
   - **Current state:** Blocks flow, leaves callback in 'processing' state (TTL-based cleanup only)
   - **Risk:** Legitimate manager-override payments cannot be recorded; must be handled offline
   - **Fix location:** BotPaymentService.php:140–143 + CashierBotController flow to request async manager approval (mirror expense approval pattern)

2. **Session carry-forward race in openShift**
   - **Issue:** Singleton drawer check at line 362–370 runs WITHOUT transaction lock; concurrent opens may both succeed
   - **Current state:** Race unlikely (Telegram single-threads per chat), but possible with manual curl/bot retries
   - **Risk:** Two shifts open on same drawer; balance errors
   - **Fix location:** Wrap singleton check in DB::transaction with lockForUpdate on CashDrawer

3. **Handover lookup uses latest('id') not latest('created_at')**
   - **Issue:** Carry-forward at line 376 uses `latest('id')`, which is not guaranteed to be most recent if rows are reordered
   - **Risk:** Low (IDs are typically monotonic), but safer to use created_at
   - **Fix location:** CashierBotController.php:375–376

4. ~~**Missing unique constraint on BeginningSaldo (shift_id, currency)**~~ — **[CORRECTED 2026-04-21]**
   The constraint does exist. Migration `2025_09_22_064137_create_beginning_saldos_table.php:22` adds
   `$table->unique(['cashier_shift_id', 'currency'])`, and prod `SHOW CREATE TABLE` confirms it as
   `beginning_saldos_cashier_shift_id_currency_unique`. Pinned by `BeginningSaldoUniquenessTest`.
   The original finding was based on a false reading — no action needed.
   Minor cosmetic: the same migration (line 25) also creates a redundant non-unique
   `index(['cashier_shift_id','currency'])` which duplicates the unique key. Harmless but
   could be dropped in a later schema cleanup.

5. **Reversal transaction created outside transaction boundary (expense rejection)**
   - **Issue:** reverseExpenseTransaction at line 125–162 is called from handleExpenseAction outside any DB::transaction; if reversal fails, money is lost
   - **Risk:** Medium (reversal failure is rare, but cascading failure is unrecoverable)
   - **Fix location:** Wrap reversal inside transaction OR implement automatic retry with exponential backoff

### 6.2 Needs Decision

1. **Manager approval for FX payment variance: forward or backward reconciliation?**
   - **Decision needed:** Should manager approval flow be:
     - **Forward (like expense):** Payment is blocked until manager approves, then proceeds atomically
     - **Backward (like refund):** Payment is recorded, manager can adjust/reject later
   - **Tradeoff:** Forward = tighter control but requires manager to be online; Backward = optimistic but harder to unwind
   - **Recommendation:** Forward (mirror expense pattern; consistency)

2. **FX tolerance thresholds: are 0.5% / 2% / 10% realistic for Uzbek market?**
   - **Decision needed:** Should tolerance be tuned based on:
     - Historical variance in CBU rates (currently ±0.2–0.5% per day)?
     - Rounding in multi-room group payments (USD total rounding)?
     - Informal cashier negotiation (are we protecting from rogue cashiers or just operator error)?
   - **Recommendation:** Review with hotel accountant; current thresholds may be too tight or too loose

3. **Callback state cleanup: should processing callbacks timeout?**
   - **Decision needed:** How long should a callback remain in 'processing' state before automatic cleanup?
     - Currently: no timeout (Telegram implicit 24–30h expiry)
     - Risk: if webhook handler crashes, callback is orphaned forever
   - **Recommendation:** Implement CRON job to expire processing callbacks > 1h old

4. **Expense approval time limit: should there be a deadline?**
   - **Decision needed:** Should owner approvals expire if not acted upon within (e.g., 24 hours)?
     - Currently: no limit (expense can be approved weeks later)
     - Risk: cascading approvals pile up; shift reconciliation delayed
   - **Recommendation:** Add approval deadline (24–48h); auto-reject old expenses; notify owner

5. **Photo archival: should cash-in photos be persisted?**
   - **Decision needed:** Photos are stored as Telegram file_id, which expires in 24h
     - Proposal: Download + store locally (S3 / local disk)
     - Risk: storage cost; privacy/compliance considerations
   - **Recommendation:** If auditing is important, implement local photo storage; else, accept 24h window

---

### 6.3 Refactor Later

1. **God controller (`CashierBotController` — 1,833 LOC)**
   - **Issue:** All flows live in one class; state machine is implicit in method naming + match statement
   - **Proposal:** Extract to workflow classes (PaymentWorkflow, ExpenseWorkflow, etc.) with shared session manager
   - **Timeline:** After A2 bugs are fixed; not a blocker for correctness
   - **Not in scope:** Do not refactor now; focus on bug fixes first

2. **State machine formalization**
   - **Issue:** State transitions are ad-hoc; hard to reason about all reachable states and paths
   - **Proposal:** Explicit state machine (Laravel Spatie/ShoppingCart model or custom)
   - **Timeline:** Phase 2 refactor; document all states first (CLAUDE.md guide map)
   - **Not in scope:** Do not implement now

3. **Beds24 booking import logic**
   - **Issue:** On-demand import at lines 1495–1570 is complex; guard + fallback + error handling scattered
   - **Proposal:** Extract to BookingImportService with clear contracts (success, partial, failed)
   - **Timeline:** After idempotency fix; own service for testing
   - **Not in scope:** Do not refactor now

4. **Callback claim/lifecycle**
   - **Issue:** Claim lifecycle (claimCallback, succeedCallback, failCallback) is distributed across services
   - **Proposal:** Extract to CallbackManager service; unify retry logic
   - **Timeline:** Phase 2; would improve testability
   - **Not in scope:** Acceptable as-is for now (working, documented)

5. **FX override approval routing**
   - **Issue:** Manager tier check at payment time (line 140–143) has no forward path; should route to approval flow
   - **Proposal:** Extract to FxApprovalService (parallel to ExpenseApprovalService); unify approval callback routing
   - **Timeline:** Implement as part of A2 bug fix #1
   - **Not in scope:** Core architecture issue; must fix now

---

## Summary Notes

### Known A2 Bugs (Confirmed)
1. **Manager FX override has no resolver:** Payment variance > 2% blocks with no approval path (BotPaymentService.php:140–143)
2. **Asymmetric approval pattern:** Expense approval is optimistic (forward) while manager payment approval is pessimistic (blocked). Should mirror.

### New Bugs Found (Not in Earlier Recon)
1. **Reversal transaction created outside transaction boundary:** If reversal fails, money is lost and expense is marked rejected anyway (OwnerBotController.php:125–162)
2. ~~**Beginning saldo has no uniqueness constraint**~~ — **[INCORRECT, CORRECTED 2026-04-21]** The
   constraint is present on day one in migration `2025_09_22_064137:22` and confirmed in prod's
   `SHOW CREATE TABLE`. See §6.1 #4 above. Pinned by `BeginningSaldoUniquenessTest`.
3. **Handover carry-forward uses latest('id') not latest('created_at'):** Non-monotonic IDs could cause wrong prior shift to be selected (low risk, but unsafe) — **FIXED in commit `3773a69` (B1)**. Now orders by `created_at DESC, id DESC` with an id tiebreaker.

### Accepted Technical Debt
- God controller (1,833 LOC) — refactor later, not blocking
- Manual FX override fallback removed — intentional hardening, not a bug
- Photo archival (24h Telegram TTL) — low risk, accept for now

---

**End of Brief**
