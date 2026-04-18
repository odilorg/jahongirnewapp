# SCOPE GATE — Hotel / Tour Boundary Classification

**Phase:** Pre-execution gate · **Generated:** 2026-04-18 · **Commit baseline:** `57bb9de`
**Binding rule:** `.claude/AUDIT_BRIEF.md` §6, plus user directive 2026-04-18 — jahongirnewapp is a **hotel-core refactor inside a shared Laravel app**. Tour runtime must not break.

> This document reclassifies every ticket in `REFACTOR_PLAN.md` through the hotel/tour lens. A ticket proceeds **only** if it passes the scope gate.

---

## 0. The gate (recap)

For every ticket, four questions must be answered:

1. **Domain** — `hotel-only` | `shared-additive` | `shared-risk` | `tour` | `deferred`
2. **Runtime impact** — `hotel-only` | `cross-domain`
3. **Tour risk** — `none` | `low` | `medium` | `high`
4. **Proof of safety** — which of the 4 safety conditions is met:
   a. hotel-only in runtime
   b. purely additive change
   c. behind explicit hotel-only flag / route / panel / namespace
   d. has regression test proving tour flows are unchanged

**A ticket pauses unless at least one proof holds.**

---

## 1. Domain truth table — from code inspection

Established during the SCOPE_GATE investigation on 2026-04-18:

### 1.1 Runtime domain of the money tables

| Table | Writers | Verdict |
|---|---|---|
| `cash_transactions` | Cashier bot · POS bot · Owner bot · Beds24 webhook · `CashierExchangeService` · `CashierExpenseService` · both `BotPaymentService` copies · Filament `CreateCashTransaction` · `RecordTransactionAction` | 🏨 **Hotel-only runtime** — zero tour writers |
| `cash_expenses` | `CashierExpenseService` (cashier bot) | 🏨 **Hotel-only runtime** |
| `cashier_shifts`, `cash_drawers`, `cash_counts`, `beginning_saldos`, `end_saldos` | Cashier bot + Filament shift flows | 🏨 **Hotel-only** (physical cash at reception) |
| `booking_fx_syncs`, `fx_manager_approvals`, `daily_exchange_rates`, `exchange_rates` | FX pipeline (cashier bot + Beds24 flows) | 🏨 **Hotel-only runtime** (FX is driven by hotel cashier) |
| `guest_payments` | OctoCallback (both), TourCalendar (tour), BookingInquiryResource (both), GygInquiryWriter (tour) | ⚠️ **SHARED runtime** |
| `supplier_payments` | TourCalendar::quickPay (only writer) | 🚌 **Tour-only runtime** (even though `Accommodation` reads it) |
| `booking_inquiries` | Website pipeline (both), GYG writer (tour), Ops bot (both), Octo (both) | ⚠️ **SHARED runtime** (central aggregate) |
| `bookings` (legacy) | 0 rows — dead | 🪦 **Dead** — killing safe |
| `beds24_bookings`, `beds24_payment_syncs`, `beds24_webhook_events`, `beds24_booking_changes` | Beds24 webhook | 🏨 **Hotel-only** (PMS is hotel) |
| `gyg_*` (6 tables) | GYG pipeline | 🚌 **Tour-only** |

### 1.2 Domain of the service layer

| Service | Domain |
|---|---|
| `BotPaymentService` (top + Fx/) | 🏨 hotel (cashier + POS bot + Beds24 FX) |
| `CashierExchangeService` · `CashierExpenseService` · `CashierShiftService` | 🏨 hotel |
| `Beds24BookingService`, `Fx/Beds24PaymentSyncService`, `ReconciliationService`, `Fx/WebhookReconciliationService` | 🏨 hotel |
| `Fx/FxManagerApprovalService`, `Fx/OverridePolicyEvaluator`, `Fx/SettlementCalculator`, `Fx/PrintPreparationService`, `FxSyncService` | 🏨 hotel |
| `Stay/CheckInService`, `Stay/CheckOutService`, `Stay/StayListService`, `Stay/BookingSummary` | 🏨 hotel |
| `OctoPaymentService` | ⚠️ shared (Octo serves hotel + tour bookings) |
| `OperatorBookingFlow` (2107 LOC), `BookingIntentParser`, `BookingBrowseService`, `BookingOpsService`, `WebsiteBookingService`, `TelegramBookingService`, `BookingInquiryNotifier`, `InquiryTemplateRenderer` | ⚠️ shared (booking orchestration used by both) |
| `Gyg*` (9 services) | 🚌 tour |
| `TourCalendarBuilder`, `TourCatalogExportService`, `Pdf/TourPdfExportService`, `Pdf/TourPdfViewModel` | 🚌 tour |
| `DriverDispatchNotifier`, `DriverService`, `GuideService` | 🚌 tour |
| `KitchenGuestService` | 🏨 hotel |
| `DailyRecapBuilder`, `TelegramReportService`, `TelegramReportFormatter`, `AdvancedReportService` | ⚠️ shared reporting |
| `Messaging/*`, `Telegram/*`, `TgDirectClient`, `TelegramBotService`, `TelegramKeyboardBuilder`, `TelegramKeyboardService` | ⚠️ shared infra |
| `StaffAuthorizationService`, `StaffNotificationService`, `StaffResponseFormatter`, `BotOperatorAuth` | ⚠️ shared infra |
| `StaticSitePageCache`, `ResponseFormatterService`, `OpenAIDateExtractorService`, `TurfirmaService`, `WebsiteAutoReplyService` | ⚠️ shared |

### 1.3 Domain of controllers

| Controller | Domain |
|---|---|
| `CashierBotController`, `TelegramPosController`, `OwnerBotController` | 🏨 hotel |
| `HousekeepingBotController`, `KitchenBotController` | 🏨 hotel |
| `Beds24WebhookController` | 🏨 hotel |
| `OctoCallbackController` | ⚠️ shared (Octo handles both) |
| `GygController`, `BookingWebhookController` | 🚌 tour |
| `TelegramController` (main booking bot), `TelegramWebhookController`, `TelegramDriverGuideSignUpController`, `OpsBotController` | ⚠️ shared |
| `Api\BookingInquiryController`, `WebsiteBookingController`, `WebhookController` | ⚠️ shared |
| `Api\InternalBotController`, `LanguageController` | ⚠️ shared infra |

### 1.4 Domain of Filament resources/pages

| Filament | Domain |
|---|---|
| `CashDrawerResource`, `CashierShiftResource`, `CashTransactionResource`, `CashExpenseResource` | 🏨 hotel |
| `AccommodationResource`, `HotelResource`, `RoomResource`, `RoomTypeResource`, `RoomRepairResource`, `LocationResource`, `AmenityResource`, `UtilityResource`, `UtilityUsageResource`, `MeterResource` | 🏨 hotel |
| `EmployeeResource`, `ShiftHandoverResource` | 🏨 hotel |
| `CashDashboard`, `DrawerBalanceWidget`, `CashTodayStats`, `CashFlowChart` | 🏨 hotel |
| `TourProductResource`, `DriverResource`, `CarResource`, `GuideResource`, `TurfirmaResource`, `ContractResource`, `RatingResource` | 🚌 tour |
| `TourCalendar`, `GygPipelineHealthWidget` | 🚌 tour |
| `BookingInquiryResource`, `GuestPaymentResource`, `SupplierPaymentResource`, `BookingPaymentReconciliationResource`, `InvoiceResource`, `ExpenseResource` | ⚠️ shared |
| `BookingsReport`, `ExpenseReports`, `GuestBalances`, `SupplierBalances`, `Reports`, `Availability` | ⚠️ shared reports |
| `StatsOverview`, `ExpenseChart`, `BotStatsOverview`, `CompactLanguageSwitcher` | ⚠️ shared |
| `UserResource`, `TagResource`, `TelegramBot*Resource`, `BotConfigurationResource`, `LanguageSettings`, `TelegramApiDocs` | ⚠️ shared infra |
| `/tourfirm` panel — `ZayavkaResource` | 🚌 tour |

---

## 2. Ticket-by-ticket classification

### Legend
- 🟢 **GO** — passes the scope gate, safe to execute
- 🟡 **CONDITIONAL** — must be split, flag-gated, or test-gated before execution
- 🔴 **PAUSE** — cannot proceed until additional guardrails are in place

---

### 🟢 Safe to proceed (8 tickets, 0 tour risk)

| ID | Ticket | Domain | Runtime | Tour risk | Safety proof |
|---|---|---|---|---|---|
| **L-001** | Fix `guest_payments` v2 migration (add `dropIfExists`) | shared-additive | hotel-only at runtime (Laravel won't re-run) | none | (b) purely additive — migration already recorded in production; edit only affects `migrate:fresh` on fresh installs. Tour runtime unaffected. |
| **L-002** | Collapse `BotPaymentService` duplicates | **hotel-only** | hotel-only | none | (a) verified 2026-04-18 — all callers (CashierBot, POS, Beds24 FX sync, RepairMissingBeds24Syncs) are hotel runtime. Zero tour callers. |
| **L-003** | Create `ledger_entries` table + model | additive | — | none | (b) new table, dormant |
| **L-004** | `RecordLedgerEntry` action + DTO + enums | additive | — | none | (b) new classes, no caller wiring |
| **L-005** | Event + projection updater skeleton | additive | — | none | (b) new event + listeners, not yet fired in production flows |
| **L-017** | CI guard (dev tooling) | infra | — | none | (b) + (c) — developer-tooling only, enforced per-PR |
| **L-022** | Delete `.backup` files | cleanup | — | none | (b) — deletes untracked backup files; zero runtime |
| **L-027** | Remove VoiceAgent remnants | cleanup | hotel-tangent | none | (a) VoiceAgent is dead code; verified route group empty |
| **L-028** | Delete `TestJob` | cleanup | — | none | (b) verified zero references |

### 🟡 Conditional — split hotel-first, tour-later (13 tickets)

These are the heart of the refactor. They are **safe for the hotel domain** but touch shared code. Each gets a mandatory split.

| ID | Ticket | Domain | Original risk | New split |
|---|---|---|---|---|
| **L-006** | Source adapters (Beds24 / Octo / GYG) | cross-domain | MEDIUM | **L-006a (🟢 GO)**: Beds24 adapter only — hotel. **L-006b (🟡)**: Octo adapter definition (no wiring) — shared-additive. **L-006c (🔴 PAUSE)**: GYG adapter — tour runtime; defer until tour-regression tests exist. |
| **L-006.5** | Shadow Ledger Mode | cross-domain | LOW | **L-006.5a (🟢)**: hotel flows only (Beds24 + cashier/POS/owner). **L-006.5b (🟡)**: shared Octo flow dual-write under flag. **L-006.5c (🔴 PAUSE)**: tour flows — after tour-regression pack is in place. |
| **L-007** | Migrate Beds24WebhookController | hotel | MEDIUM | 🟢 **GO** — controller's only writer is Beds24, which is hotel PMS. No tour path. |
| **L-008** | Migrate OctoCallback + kill legacy | shared | HIGH | 🟡 Split: **L-008a (🟢)** kill legacy `handleBookingCallback` (0 rows in `bookings` — dead code). **L-008b (🟡)** migrate `handleInquiryCallback` to adapter — this serves tour inquiries; requires tour-regression tests + shadow parity before flag flip. **L-008c (🟡)** fix amount-drift — once in shadow, both legacy and new must write the actual `paidSum`. |
| **L-009** | Migrate cashier/POS/owner bots | hotel | MEDIUM | 🟢 **GO** — all three bots are hotel runtime (cashier = reception, POS = hotel terminal, owner = alerts). |
| **L-010** | Migrate Filament money writes | cross-domain | MEDIUM | 🔴 **PAUSE for TourCalendar** — tour-critical UI. Split: **L-010a (🟢)** `CreateCashTransaction` page (hotel only). **L-010b (🟡)** `BookingInquiryResource::markPaid` — shared; flag + regression test. **L-010c (🔴 PAUSE)** `TourCalendar::quickGuestPay` + `::quickPay` — tour runtime; requires full tour regression suite + explicit tour owner sign-off. |
| **L-011** | Balance projections (cash_drawer_balances, shift_balances, daily_cash_flow) | hotel | LOW | 🟢 **GO** — projections of hotel-only tables. |
| **L-012** | Payment projection models (read-only facade) | cross-domain | MEDIUM | 🟡 Split: **L-012a (🟢)** `CashExpense` read-only facade (hotel-only writers). **L-012b (🔴 PAUSE)** `GuestPayment` and `SupplierPayment` — tour writers exist; requires tour flows migrated FIRST (L-008b + L-010c) before read-only guard can be turned on. |
| **L-013** | `LedgerReportService` consolidating reporting math | shared | MEDIUM | 🟡 Split: **L-013a (🟢)** implement service querying projections for hotel-only reports (cash dashboard, expenses, drawer balance). **L-013b (🟡)** add tour-scoped report methods with shadow comparison against `AdvancedReportService`. Do not retire `AdvancedReportService` until tour shadow-match 7 days. |
| **L-014** | Migrate Filament readers | cross-domain | MEDIUM | 🟡 Split: **L-014a (🟢)** hotel widgets + `CashDashboard`, `GuestBalances` (hotel portion). **L-014b (🔴 PAUSE)** `TourCalendar` read paths, `BookingsReport` tour queries — shadow-match per page required. |
| **L-015.5** | `ledger:diff` tool | additive | LOW | 🟢 **GO** — read-only diagnostic, no writes |
| **L-015** | Backfill historical data | shared | MEDIUM | 🟡 Phased per plan (7d → 30d → full). Initial 7-day pass: hotel tables only (`cash_transactions`, `cash_expenses`, `cashier_shifts`, `beginning_saldos`, `end_saldos`). Subsequent passes extend to shared tables **only after their mutation sites are migrated**. |
| **L-016** | Consolidate reconciliation | hotel (ledger uses Beds24 truth) | MEDIUM | 🟡 Hotel-only in write path, but touches shared reporting — flag-gated rollout + shadow-compare reconciliation output for 7 days. |
| **L-018** | Runtime write firewall | cross-domain | MEDIUM | 🟡 Enable in **shadow mode** (warn only) for 30 days; enforce only after tour mutation sites are migrated (L-008b, L-010c). Otherwise it blocks live tour writes. |

### 🔴 Paused — blocked on tour-regression infrastructure (6 tickets)

| ID | Ticket | Reason |
|---|---|---|
| **L-006c** | GYG adapter implementation | Tour runtime. Requires tour-regression pack. |
| **L-008b** | Full Octo inquiry migration | Serves tour inquiries. Needs tour-test coverage + shadow parity. |
| **L-010c** | TourCalendar migration | Primary tour operator UI. High-risk. |
| **L-012b** | GuestPayment/SupplierPayment read-only | Cannot block live tour writes. |
| **L-019** | Freeze legacy tables (triggers) | Affects tables shared with tour. Must follow full tour migration. |
| **L-020** | Drop legacy tables | Irreversible. Tour-ready is a prerequisite. |

### 🟢 P2 cleanup — run in parallel, mostly tour-neutral

| ID | Ticket | Verdict |
|---|---|---|
| **L-021** | DTO consolidation | 🟡 shared imports — run `composer dump-autoload` + full test suite; low risk. |
| **L-023** | Delete `_archived/` Filament | 🟢 GO — zero references verified |
| **L-024** | Remove `ScheduledMessage*` (disabled) | 🟢 GO — feature already disabled |
| **L-025** | Consolidate TelegramKeyboard* | 🟡 shared infra — wait until L-009 done, run bot regression |
| **L-026** | Rename `FxSourceTrigger` → `FxRateSyncTrigger` | 🟢 GO (hotel-scoped enum) |
| **L-029** | Introduce Form Requests | 🟡 shared — wait until L-007..L-010 done |

---

## 3. New "Tour Regression Pack" — prerequisite for 🔴 tickets

Before any of the PAUSE tickets can proceed, the following automated regression pack must exist and pass on every PR:

### 3.1 Minimum tour regression suite

| Test | Covers |
|---|---|
| `tour_inquiry_creates_from_website` | Website form → BookingInquiry (source=direct) → Octo link |
| `tour_inquiry_creates_from_gyg_import` | GYG email → GygInquiryWriter → BookingInquiry (source=gyg) |
| `tour_inquiry_payment_via_octo` | Octo callback on inquiry → status=confirmed + GuestPayment row |
| `tour_calendar_renders_with_bookings` | TourCalendar Filament page loads with seed inquiries |
| `tour_calendar_quick_guest_pay` | TourCalendar::quickGuestPay creates GuestPayment |
| `tour_calendar_quick_supplier_pay` | TourCalendar::quickPay creates SupplierPayment |
| `tour_mark_paid_action` | BookingInquiryResource::markPaid flows |
| `tour_reminders_send` | `tour:send-reminders` command for synthetic inquiry |
| `tour_driver_dispatch_fires` | DriverDispatchNotifier triggers at T-1h |
| `gyg_supplier_api_endpoints_respond` | All 6 `/gyg/1/*` routes return expected shapes |
| `tourfirm_panel_zayavka_crud` | `/tourfirm` panel CRUD unchanged |

**This pack is a prerequisite**, not optional. It gets its own ticket before 🔴 work unblocks:

- **L-T01 — Tour regression pack** (NEW, must ship before L-006c / L-008b / L-010c / L-012b / L-019 / L-020)

---

## 4. New execution sequence (replacing §2 of REFACTOR_PLAN.md)

```
Phase A — Stabilization (P0, hotel-safe)
  L-001  🟢 guest_payments migration fix (shared-additive, dormant)
  L-002  🟢 BotPaymentService collapse (hotel-only verified)

Phase B — Foundation (all additive, zero tour runtime)
  L-003  🟢 ledger_entries schema
  L-004  🟢 RecordLedgerEntry action + DTO + enums
  L-005  🟢 event + projection updater skeleton
  L-015.5 🟢 ledger:diff tool (read-only diagnostic)
  L-017  🟢 CI guard
  L-018  🟡 runtime write firewall — WARN MODE ONLY in this phase

Phase C — Hotel adapter migration (zero tour runtime change)
  L-006a 🟢 Beds24 adapter
  L-006.5a 🟢 Shadow mode for hotel flows
  L-007  🟢 Beds24WebhookController migrated
  L-009  🟢 Cashier/POS/Owner bots migrated
  L-010a 🟢 Filament CreateCashTransaction migrated
  L-011  🟢 Balance projections (cash_drawer_balances, shift_balances)
  L-012a 🟢 CashExpense read-only facade
  L-013a 🟢 LedgerReportService — hotel portion

Phase D — Tour regression prerequisites
  L-T01  🟢 Tour regression pack (NEW) — blocker for shared-risk work
  L-014a 🟢 Hotel widget reads migrated
  P2 cleanup tickets that are tour-safe (L-022, L-023, L-024, L-026, L-027, L-028)

Phase E — Shared migration under regression gate (only after L-T01 green for 7 days)
  L-006b 🟡 Octo adapter (definition only)
  L-006.5b 🟡 Shadow mode for Octo
  L-008a 🟡 Kill legacy Octo Booking path (bookings table = 0 rows, dead)
  L-008b 🟡 Migrate Octo inquiry path (with amount-drift fix) — under flag
  L-010b 🟡 BookingInquiryResource markPaid — under flag
  L-013b 🟡 LedgerReportService tour portion (shadow-compare AdvancedReportService)
  L-016 🟡 Consolidate reconciliation
  L-021 🟡 DTO consolidation
  L-025 🟡 TelegramKeyboard merge
  L-029 🟡 Form Requests

Phase F — Tour migration (with full regression on every PR)
  L-006c 🔴 → 🟡 GYG adapter wired
  L-006.5c 🔴 → 🟡 Shadow mode for tour flows
  L-010c 🔴 → 🟡 TourCalendar migrations (guest + supplier pay)
  L-012b 🔴 → 🟡 GuestPayment + SupplierPayment read-only (only after all writers migrated)
  L-014b 🔴 → 🟡 Tour Filament readers migrated
  L-018  🟡 → 🟢 Firewall enforce=true

Phase G — Hardening & cutover
  L-015 🟡 Backfill (phased 7d → 30d → full)
  L-019 🔴 → 🟡 Freeze legacy tables (triggers)
  (30-day observation window)
  L-020 🔴 → 🟡 Drop legacy tables
```

---

## 5. What this changes for L-001 specifically

**L-001 remains 🟢 GO under the new rule:**

| Gate question | Answer |
|---|---|
| Domain | **shared-additive** |
| Runtime impact | **hotel-only at runtime** — Laravel does not re-execute migrations already in the `migrations` table. The change only affects `migrate:fresh` on fresh installs. |
| Tour risk | **none** — no production path executes the modified code |
| Proof | **(b) purely additive** — 1-line addition to a migration file that Laravel will skip in production. Tour flows unaffected. |

**L-001 proceeds with Option A (edit existing file) as previously planned.**

---

## 6. What this changes for L-002 specifically

**L-002 remains 🟢 GO — verified hotel-only:**

`BotPaymentService` callers (verified 2026-04-18):
- `CashierBotController` — 🏨 hotel reception
- `Services/FxSyncService` — 🏨 hotel FX sync
- `Services/Fx/Beds24PaymentSyncService` — 🏨 hotel Beds24 FX
- `Services/Fx/FxManagerApprovalService` — 🏨 hotel approval chain
- `Console/Commands/RepairMissingBeds24Syncs` — 🏨 hotel repair job
- DTOs (neutral, no runtime invocation)

**Zero tour callers.** Collapse the duplicate; hotel-only change, tour runtime unaffected.

---

## 7. Binding from this document

From this commit forward:

- **Every ticket entry** in future planning docs **must carry a scope-gate header** with the 4 fields (domain / runtime / tour-risk / proof).
- **CI enforcement (optional future):** a `bin/check-scope-gate.sh` that fails a PR if its description lacks the header.
- **No ticket labelled 🔴 PAUSE executes** until the "Tour Regression Pack" (L-T01) has been built and is green.
- **Hotel-first sequencing is non-negotiable.** Every shared-runtime change sits behind a feature flag and a regression gate.

---

## 8. Recommendation for the next action

With this gate applied:

1. **L-001 is cleared.** Safe to execute Option A — the 1-line `Schema::dropIfExists` edit. Tour runtime cannot be affected because the change does not execute on production.
2. **L-002 is cleared.** Safe to execute next — all callers verified hotel-only.
3. **L-003 through L-005 are cleared.** Additive foundations.
4. **Before proceeding past Phase C** (hotel adapter migration), the **L-T01 tour regression pack** must be spec'd and built. This unblocks the shared-runtime tickets.

---

**Status:** Scope gate applied to all 29 original tickets. Hotel-safe work path identified. L-001 and L-002 cleared. Awaiting final execution approval on L-001.
