# FEATURE BACKLOG — Jahongir Hotel Operations System

**Started:** 2026-04-18 · rolling · **not an implementation plan**

> Ideas and enhancements **discovered during the Phase 1–5 audit**.
> These are **not** tickets. They are candidates for roadmap planning.
> Implementation happens only after architecture stabilizes (post-Phase 5 execution).

---

## How to use this file

- Append-only. Do not delete; strikethrough when obsolete.
- Group by the 4 core objectives: Money · Coordination · Visibility · Reporting.
- Tag with ⭐ high / ★ medium / · low based on likely business value.
- Cross-reference to architecture doc section where the idea arose.

---

## 💰 Money control

### ⭐ Ledger review UI for `data_quality='manual_review'` rows
Filament page listing ledger entries flagged by backfill (L-015) or reconciliation (L-016) for ops inspection. Each row has "approve / adjust / reverse" actions that call the appropriate action.
**Source:** `TARGET_ARCHITECTURE.md §6.4`; deferred from Phase 5 per decision #6.

### ⭐ Operator attribution on every ledger entry
Every ledger write must carry `created_by_user_id` or `created_by_bot_slug`. Backfilled rows currently allow NULL — add a follow-up pass that infers attribution from historical `telegram_bot_access_log` joins.
**Source:** `MONEY_FLOW_DEEP_DIVE.md §6.2`.

### ⭐ Multi-currency shift balance view
Today `CashierShift` stores a single `expected_end_saldo`. Projections already track per-currency; expose per-currency balances in the shift close form so cashiers see USD + UZS + EUR + RUB discrepancies separately.
**Source:** `MONEY_FLOW_DEEP_DIVE.md §2.1` + `DOMAINS.md §B2.2`.

### ★ Reconciliation drift dashboard
Post-L-016, `ReconciliationAdjust` entries record drift between internal ledger and Beds24 authoritative truth. Build a Filament page showing these over time — a leading indicator of integration health.
**Source:** `TARGET_ARCHITECTURE.md §5.4`.

### ★ Real-time drawer-cashier alerts
Subscribe to `LedgerEntryRecorded`; push Telegram notifications when a drawer swings > threshold within a window (possible fraud signal).
**Source:** original "owner alert" pattern, generalized.

### ★ Refund action as first-class
Today refunds exist as ledger types (`AccommodationRefund`, `TourRefund`, `OtherRefund`) but there is no dedicated UI/action to issue one against a specific booking. Add `IssueRefund` action that links to the original payment entry and emits a reversal.
**Source:** `TARGET_ARCHITECTURE.md §3.3`.

### ★ Octo refund support (currently missing)
Octo supports refunds but `OctoCallbackController` has no refund handling. Add refund callback support using `IssueRefund`.

### · Automated end-of-day shift close reminder
If a shift is open after midnight, ping the cashier (and escalate to owner after N hours).

### · Cashier performance metrics
Per-cashier stats: transactions handled, average processing time, variance from expected, override frequency.

---

## 🧑‍🍳 Operational coordination (Task Core)

> **Phase 4.5 scope — requires its own deep-dive before detailed design.**

### ⭐ Unified `Task` aggregate (housekeeping / kitchen / maintenance)
`RoomCleaning`, `RoomRepair`, `RoomIssue` share a lifecycle but are 3 parallel tables. Unify into `Task(type, assignee, status, events[], comments[])` with polymorphic context (room / booking / inquiry).
**Source:** `DOMAINS.md §A4`, `§A11`, `§C3.4`.

### ⭐ Service layer for Housekeeping (currently controller-is-domain)
`HousekeepingBotController` at 1,848 LOC IS the housekeeping domain. Extract `HousekeepingService` + actions: `CreateCleaningTask`, `AssignTask`, `CompleteTask`, `ReopenTask`, `RecordIssue`.
**Source:** `DOMAINS.md §A4`.

### ⭐ Kitchen meal-count as predicted from tasks
Today `KitchenMealCount` is manually entered. With `Task(type=MealRequest)` it can be auto-derived from upcoming stays + tour bookings.
**Source:** `DOMAINS.md §A5`.

### ★ Room status as state machine
`Room` has a status string; `RoomStatus` + `RoomPriority` are separate models. Pick one authoritative state with an explicit machine: `Clean → Dirty → Cleaning → Inspected → OutOfService`. Transitions emit events.
**Source:** `DOMAINS.md §A3`.

### ★ Task escalation rules
If a high-priority task is unassigned > X minutes, escalate via Telegram chain (cleaner → supervisor → manager).

### ★ Guest-facing task status visibility (optional)
For specific task types (e.g. "early check-in requested"), allow guests to see status via a tokenized link.

---

## 👁 Operational visibility

### ⭐ Central shift handover dashboard
`ShiftHandover` model exists but no holistic dashboard. Combine open tasks + financial summary + room status at shift boundary.

### ⭐ Operator attribution page
Filament page showing "who did what today" — aggregates `StaffAuditLog` + `LedgerEntry.created_by_*` + `Task.assignee` into a per-user timeline.

### ★ Inquiry pipeline health widget (existing for GYG, generalize)
`GygPipelineHealthWidget` exists. Generalize: show health of website inquiry → booking → payment pipeline with stage conversion rates.

### ★ Real-time occupancy view
Beds24 is authoritative but queried on-demand. Push webhook events into a read model for sub-second "is room X available right now" queries.

### · Issue heatmap by room / time
From `RoomIssue` / `RoomRepair`, generate a heatmap — which rooms repeatedly need maintenance.

---

## 📊 Owner-level reporting

### ⭐ Monthly P&L report
Combine `LedgerReportService::monthlyFinancialSummary` + occupancy data into a single owner-facing report with trend lines.

### ⭐ Booking source attribution ROI
Per source (direct website, GYG, Beds24-OTA, operator) — revenue, commission paid, net contribution, churn. Drives source-mix decisions.

### ★ Cash float health tracker
Multi-day graph of drawer balances per currency. Detects over-holding (cash should be deposited) or under-holding (shortage risk).

### ★ Daily "exception" digest
Replaces the current recap. Only surfaces exceptions: unclosed shifts, unpaid confirmed bookings, unassigned tasks, pending approvals, reconciliation drift.

### ★ Supplier payment aging report
Outstanding supplier obligations sorted by age, with projected cash-out.

### · Year-over-year comparison
Once data spans 2+ years, YoY trend per KPI.

---

## 🔌 Integration / platform

### ⭐ Beds24 webhook signature verification (security)
Currently no auth on `/beds24/webhook`. Beds24 supports signed webhooks. Implement signature verification.
**Source:** `ROUTES_AND_ENTRYPOINTS.md §2.1`, `§5`.

### ⭐ `/telegram/ops/webhook` signature middleware
Single Telegram bot without the verification middleware. Bring it to parity with the other 7 bots.
**Source:** same.

### ⭐ GYG two-way sync for cancellations
Today GYG → us is one-way. Cancellations initiated by us are not pushed back via the GYG notify endpoint reliably.
**Source:** `DOMAINS.md §A9.2`.

### ★ Channel manager abstraction
Beds24 and GYG are both "channel-like". Extract `ChannelAdapter` interface; a second OTA becomes plug-in.

### ★ WhatsApp official-API upgrade
Current `Messaging/WhatsAppSender` uses an unofficial path (or at least, depth unclear). Evaluate migration to WhatsApp Business API.

### · Octo tokenized-card recurring payments
If platform becomes member-based, tokenized recurring charges reduce friction.

---

## 🧰 Developer experience / platform hygiene

### ⭐ PROJECT_CONTEXT.md refresh automation
Doc claims Laravel 11; reality is Laravel 10. Add a CI job that diffs claims against `composer.json` and fails the build on drift.
**Source:** `INVENTORY.md §10`.

### ⭐ Domain-import lint rule
Disallow cross-domain model imports except through explicit ports (e.g., `app/Domains/Reservations/` must not import `app/Domains/Finance/` directly).

### ★ `AdvancedReportService` deprecation warning
After L-013, any call to it emits a runtime deprecation notice. Removed once callers migrated (L-014).

### ★ Test the bot session transitions
Bot session state machines (`TelegramPosSession`, etc.) lack tests. Add state-transition property-based tests.

### ★ Replay-all command
`WebhookReplay` exists. Extend: `ledger:replay-all {--from=} {--to=}` — replays every webhook / bot callback through the new ledger actions in a dry-run mode to find edge cases. Useful before cutover.

### · Static analysis (Larastan) level raise
No visible Larastan config yet. Introduce at level 5+, raise gradually.

### · Automatic coverage target
Aim for 60% line coverage on `app/Actions/Ledger/*` and `app/Services/Ledger/*` (financial core).

---

## 🧬 Data quality

### ⭐ Orphan detector
Scheduled command that reports: ledger entries with no projection row, projection rows with no ledger entry, bookings with mismatched invoice vs ledger sum.

### ⭐ `payment_method` normalization sweep
Post-L-003, one-time backfill of historical string values into the `PaymentMethod` enum; unmatched values surfaced for review.
**Source:** `MONEY_FLOW_DEEP_DIVE.md §3.1`.

### ★ Amount-drift detector
Monitors live Octo callbacks: if `paid_sum != price_quoted` by > X%, alert. Catches pricing-update lag or currency-conversion issues.

### · Typed amounts (money-object)
Introduce a `Money(amount, currency)` value object; pass it through actions instead of separate amount+currency scalars. Prevents category of bugs.

---

## 🏗️ Longer-term architecture

### ⭐ Task core (Phase 4.5 full scope)
Unified task aggregate + lifecycle + bot adapters + cross-domain event bus. Separate plan once deep-dive is done.

### ★ `Booking` legacy model retirement
Dual-aggregate `Booking` + `BookingInquiry` cleanup. Full transition once L-008 (Octo kill-legacy) completes.
**Source:** `DOMAINS.md §A1`, `§A12`.

### ★ Session-model consolidation
`TelegramPosSession`, `TelegramBookingSession`, `OperatorBookingSession` → one `BotSession(bot_slug, user_id, state, context, expires_at)`.
**Source:** `DOMAINS.md §A12`.

### ★ Event-sourced aggregate rebuild
Ledger is event-sourced by nature. Consider promoting the pattern: snapshots for aggregates, replay for projections, audit queries trivially answerable.

### · Multi-tenant support (hotel-scoped scoping)
If additional properties are added beyond Guest House + Premium, introduce `property_id` scoping across the domain. Current assumption: two properties is hard-coded-friendly.

---

## 📅 Prioritized first-draft roadmap

*(not committed — reviewer's sketch)*

**Weeks 1–2 (P0 + foundation)** — L-001, L-002, L-003, L-004
**Weeks 3–5 (adapters)** — L-005, L-006, L-007, L-008 (Octo amount-drift fixed here)
**Weeks 4–7 (parallel)** — L-009 (bots), L-010 (Filament), L-011–L-012 (projections), P2 cleanup tickets
**Weeks 6–8** — L-013, L-014 (reporting migration)
**Week 7** — L-015 backfill dry-runs
**Week 8–9** — L-016, L-017, L-018
**Week 10** — L-019 freeze + begin observation window
**Weeks 10–14 (observation)** — monitoring, soak
**Week 14+** — L-020 drop legacy

**Then:** Phase 4.5 — Task core deep-dive.

---

*This document is rolling. Append new ideas here when they surface. Never implement from this file directly — promote to `REFACTOR_PLAN.md` via phased planning.*
