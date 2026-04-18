# DOMAINS — Jahongir Hotel Operations System

**Phase:** 2 · **Generated:** 2026-04-18 · **Commit baseline:** `c6d9850`
**Read-only analysis.** Source inputs: `INVENTORY.md`, `ROUTES_AND_ENTRYPOINTS.md`, code inspection.

---

## 0. How this document is organised

Part A maps the 10 operational domains of the system.
Part B elevates **Financial System** to a **super-domain** — a deep, separate map that feeds Phase 3.5 (`MONEY_FLOW_DEEP_DIVE.md`) and Phase 4 (ledger core design).

Per-domain template:
- **Purpose** — what it exists for
- **Entrypoints** — HTTP / bot / cron / Filament
- **Models** — Eloquent tables owned by the domain
- **Application layer** — services / actions / jobs / commands
- **External deps** — integrations, other domains
- **Complexity score** — 1 (trivial) → 5 (critical, load-bearing)
- **Pain points** — concrete observations with file:line where relevant

---

# PART A — Operational domains

## A1. Reservations & Inquiries

**Purpose:** capture demand from website/OTA/operator and evolve it through a unified inquiry lifecycle.

**Central entity:** `BookingInquiry` — the dominant aggregate post-Phase 8 (April 2026). Appears in 25+ callers across all layers. Replaces legacy `Booking` in most flows.

| Aspect | Value |
|---|---|
| **Entrypoints** | `POST /api/v1/inquiries` · website inquiries pipeline · GYG email → `GygBookingApplicator` · Ops bot · Filament `BookingInquiryResource` (1,960 LOC) |
| **Models** | `BookingInquiry`, `Booking`, `BookingDriver`, `BookingTour`, `InquiryStay`, `InquiryReminder`, `OperatorBookingSession`, `TelegramBookingSession`, `Zayavka` (legacy) |
| **Services** | `OperatorBookingFlow` (🔴 **2,107 LOC**, domain brain), `WebsiteBookingService`, `TelegramBookingService`, `BookingIntentParser`, `BookingBrowseService`, `BookingOpsService`, `BookingInquiryNotifier`, `InquiryTemplateRenderer`, `WebsiteAutoReplyService` |
| **Actions** | ❌ none — this is where the gap is most visible |
| **Jobs** | `ProcessBookingMessage` (🔴 **1,025 LOC** — god-job, contains its own intent parser) |
| **Observers** | `BookingObserver`, `BookingInquiryObserver`, `InquiryStayObserver` |
| **Commands** | `InquirySendReminders`, `InquirySendPaymentReminders`, `SendBookingNotification` |
| **External deps** | GYG ingestion, Octo payment, Beds24 sync (via downstream domain), Telegram |
| **Complexity** | **5/5** — central to the business |

**Pain points:**
- `OperatorBookingFlow.php:2107` — single class orchestrating intent→parse→validate→persist→notify→confirm across every bot flow. Domain brain, no sub-structure.
- `ProcessBookingMessage.php:1025` — duplicate intent parsing logic also lives here; two sources of truth for same use case.
- Legacy `Booking` model + new `BookingInquiry` both alive → **dual write risk**. `Booking` feeds Filament calendar, `BookingInquiry` feeds new website pipeline.
- Validation happens inline in `OperatorBookingFlow` and `WebsiteBookingService`; only 1 Form Request exists (`StoreBookingInquiryRequest`).

---

## A2. Tour Catalog & Dispatch

**Purpose:** tour products, pricing tiers, directions (itineraries), and same-day dispatch (driver/guide assignment, supplier ping).

| Aspect | Value |
|---|---|
| **Entrypoints** | `GygController@*` (supplier API, 6 routes) · Filament `TourProductResource`, `TourCalendar` page (725 LOC, also mutates `GuestPayment` + `SupplierPayment`) · `ImportToursFromStatic` command (717 LOC) |
| **Models** | `TourProduct`, `TourProductDirection`, `TourPriceTier`, `Tour` (legacy), `TourPrice`, `BookingTour`, `BookingDriver`, `SoldTourDriver`, `Driver`, `Guide`, `DriverRate`, `GuideRate`, `TourRepeaterDriver`, `TourRepeaterGuide`, `TourExpense`, `Car`, `CarBrand`, `CarDriver` |
| **Services** | `TourCatalogExportService`, `TourCalendarBuilder`, `DriverDispatchNotifier` (🟡 738 LOC), `DriverService`, `GuideService`, `Pdf/TourPdfExportService`, `Pdf/TourPdfViewModel` |
| **Observers** | `TourProductObserver`, `TourProductDirectionObserver`, `TourPriceTierObserver` |
| **Commands** | `ImportToursFromStatic`, `ExportToursWebsiteData`, `ExportToursWebsitePdfs`, `TourSendReminders`, `TourSendHotelRequests`, `TourSendReviewRequests`, `PingImminentTours`, `RolloutTourPricingLoader`, `RollbackTourPricingLoader` |
| **External deps** | GYG inbound, WhatsApp (supplier ping), Email |
| **Complexity** | **4/5** |

**Pain points:**
- `TourCalendar.php:160`, `:432` — Filament page creates `GuestPayment` **and** `SupplierPayment` records directly. Money mutation from presentation layer.
- `ImportToursFromStatic.php` at 717 LOC — a workflow service masquerading as a console command.
- `DriverDispatchNotifier` at 738 LOC — mixes dispatch decisioning with notification rendering.
- Tour-pricing rollout/rollback commands suggest recent instability.

---

## A3. Accommodation & Rooms

**Purpose:** hotel/room inventory, meter readings, utilities, repairs tracking.

| Aspect | Value |
|---|---|
| **Entrypoints** | Filament `HotelResource`, `RoomResource`, `RoomTypeResource`, `RoomRepairResource`, `LocationResource`, `AmenityResource`, `UtilityResource`, `UtilityUsageResource`, `MeterResource`, `AccommodationResource`. `Availability` page (used as API) |
| **Models** | `Hotel`, `Accommodation`, `AccommodationRate`, `Room`, `RoomType`, `RoomStatus`, `RoomPriority`, `RoomUnitMapping`, `Location`, `Amenity`, `Utility`, `UtilityUsage`, `Meter` |
| **Services** | `Beds24RoomMapService` — maps Beds24 room IDs to internal records |
| **Actions** | ❌ none |
| **External deps** | Beds24 (source of truth for availability) |
| **Complexity** | **3/5** |

**Pain points:**
- Room status is a **string on `Room` + separate `RoomStatus` model + `RoomPriority` model** — 3 overlapping concepts. Which is authoritative?
- `AccommodationRate` (pricing) and the shifting meaning of `Accommodation` vs `Room` vs `RoomType` suggest domain-model confusion.
- No read-model / availability projection service — availability is computed live.

---

## A4. Housekeeping

**Purpose:** room cleaning, repairs, issue reporting via staff bots.

| Aspect | Value |
|---|---|
| **Entrypoints** | `POST /api/telegram/housekeeping/webhook` → `HousekeepingBotController` (🔴 **1,848 LOC**) · Filament `RoomRepairResource` · `ShiftHandoverResource` |
| **Models** | `RoomCleaning`, `RoomRepair`, `RoomIssue`, `ShiftHandover` |
| **Services** | ❌ **none dedicated** — all logic inside `HousekeepingBotController` |
| **Actions** | ❌ none |
| **Complexity** | **3/5** (business-critical but small scope) |

**Pain points:**
- **No service layer for housekeeping.** All cleaning/assignment/status logic lives inside the bot controller. The controller **is** the domain.
- `RoomCleaning`, `RoomRepair`, `RoomIssue` — 3 parallel "task-like" tables with no shared abstraction. Prime candidate for **unified Task entity** (`AUDIT_BRIEF.md` §3.2).
- Operational coordination happens via Telegram chat history, not via tracked tasks.

---

## A5. Kitchen / F&B

**Purpose:** meal count forecasting, kitchen notifications.

| Aspect | Value |
|---|---|
| **Entrypoints** | `POST /api/telegram/kitchen/webhook` → `KitchenBotController` (727 LOC) |
| **Models** | `KitchenMealCount` |
| **Services** | `KitchenGuestService` |
| **Complexity** | **2/5** (narrow) |

**Pain points:**
- `KitchenMealCount.php` is the **only model that uses raw `DB::` bypassing Eloquent** (found in Phase 1). Phase 3 to audit.
- Same pattern as Housekeeping: bot controller holds the logic; no service layer of its own.

---

## A6. Guest & Ratings

**Purpose:** guest records, post-stay ratings, lost & found, contracts.

| Aspect | Value |
|---|---|
| **Entrypoints** | Filament `GuideResource`, `RatingResource`, `ContractResource` |
| **Models** | `Guest`, `Rating`, `LostFoundItem`, `Contract`, `SpokenLanguage` |
| **Services** | `GuideService` |
| **Jobs** | `GenerateContractPdf` |
| **Complexity** | **2/5** |

**Pain points:**
- Thin domain — mostly CRUD. The main risk is that `Guest` is referenced from many other domains; keeping it clean is important but currently not problematic.

---

## A7. Staff & Bot Infrastructure

**Purpose:** staff directory, shift handover, Telegram bot plumbing, access control.

| Aspect | Value |
|---|---|
| **Entrypoints** | Filament `EmployeeResource`, `ShiftHandoverResource`, `TelegramBotResource`, `TelegramServiceKeyResource`, `TelegramBotConversationResource`, `BotConfigurationResource` · Owner bot webhook · Internal bot proxy API |
| **Models** | `User`, `Employee`, `Member`, `Partner`, `ShiftTemplate`, `ShiftHandover`, `TerminalCheck`, `BotOperator`, `BotConfiguration`, `BotAnalytics`, `TelegramBot`, `TelegramBotSecret`, `TelegramBotConversation`, `TelegramBotAccessLog`, `TelegramServiceKey`, `TelegramPosSession`, `TelegramPosActivity`, `TelegramConversation`, `TelegramBookingSession`, `StaffAuditLog` |
| **Services** | `Telegram/BotResolver`, `Telegram/TelegramTransport`, `Telegram/BotSecretProvider`, `Telegram/BotAuditLogger`, `Telegram/LegacyConfigBotAdapter`, `Telegram/FallbackBotResolver`, `TelegramBotService`, `TelegramKeyboardBuilder` + `TelegramKeyboardService` (⚠️ overlap), `StaffAuthorizationService`, `StaffNotificationService`, `StaffResponseFormatter`, `BotOperatorAuth` |
| **Jobs** | `ProcessTelegramMessage`, `ProcessTelegramUpdateJob`, `SendTelegramMessageJob`, `SendTelegramNotificationJob` |
| **Complexity** | **4/5** — large surface, all operational flows rely on it |

**Pain points:**
- `TelegramKeyboardBuilder` + `TelegramKeyboardService` — two services with overlapping responsibility. One may be vestigial.
- 8 bots each with their own controller (1,848, 1,819, 1,492, 1,061, 727, 592 LOC + 2 small) → **no shared bot-command dispatcher kernel**. Every bot re-implements session management, intent routing, reply formatting.
- Sessions live in three places: `TelegramPosSession`, `TelegramBookingSession`, `OperatorBookingSession`. No common contract.

---

## A8. Messaging & Notifications

**Purpose:** outbound email / WhatsApp / Telegram messaging; reminders.

| Aspect | Value |
|---|---|
| **Entrypoints** | Commands (reminders) · Filament `ChatResource`, `ScheduledMessageResource` (both moved to `_archived/` — deprecated Apr 2026) |
| **Models** | `Chat`, `ScheduledMessage`, `ScheduledMessageChat`, `IncomingWebhook`, `InquiryReminder` |
| **Services** | `Messaging/EmailSender`, `Messaging/WhatsAppSender`, `Messaging/GuestContactSender`, `Messaging/SendResult`, `TelegramReportService`, `TelegramReportFormatter` (🟡 793 LOC), `TgDirectClient` |
| **Jobs** | `SendTelegramMessageJob`, `SendTelegramNotificationJob` |
| **Mail** | 3 Mailables |
| **Complexity** | **3/5** |

**Pain points:**
- `SendScheduledMessagesCommand` disabled 2026-04-15 (code comment: *"scheduled_messages table unused; feature deprecated"*) — **dead models remain** (`ScheduledMessage`, `ScheduledMessageChat`).
- `TelegramReportFormatter.php` is 793 LOC and `TelegramReportService.php` is 554 LOC — a lot of logic in formatting/report construction. Possibly better as templating.
- WhatsApp integration depth unclear — Phase 3 to audit `WhatsAppSender`.

---

## A9. External Integrations (Beds24 / GYG / Octo)

**Purpose:** adapters to external systems. Ingestion, sync, reconciliation.

### A9.1 Beds24 (hotel PMS — source of truth for room inventory)

| Aspect | Value |
|---|---|
| **Entrypoints** | `POST /api/beds24/webhook` (⚠️ no auth) · scheduled repair commands |
| **Models** | `Beds24Booking`, `Beds24BookingChange`, `Beds24WebhookEvent`, `Beds24PaymentSync` |
| **Services** | `Beds24BookingService` (🟡 1,017 LOC), `Beds24RoomMapService`, `ReconciliationService`, `Fx/Beds24PaymentSyncService`, `Fx/WebhookReconciliationService` |
| **Jobs** | `ProcessBeds24WebhookJob`, `Beds24PaymentSyncJob` |
| **Commands** | `RefreshBeds24Token`, `Beds24Setup`, `RepairFailedBeds24Syncs`, `RepairMissingBeds24Syncs`, `RepairStuckBeds24Syncs`, `SurfaceFailedBeds24Syncs`, `RunReconciliation` |
| **External deps** | Beds24 API (OAuth2-style token) |
| **Complexity** | **5/5** |

**Pain points:**
- 4 repair commands indicates **recurring sync failures**. Symptom of fragile integration.
- Webhook endpoint has no signature check.
- Reconciliation logic split between `ReconciliationService` and `Fx/WebhookReconciliationService` — two reconciliation paths.

### A9.2 GetYourGuide (channel manager via email)

| Aspect | Value |
|---|---|
| **Entrypoints** | `GET\|POST /api/gyg/1/*` (6 supplier API routes) · scheduled IMAP pipeline `gyg:fetch-emails` → `gyg:process-emails` → `gyg:apply-bookings` |
| **Models** | `GygBooking`, `GygReservation`, `GygProduct`, `GygAvailability`, `GygInboundEmail`, `GygNotification` |
| **Services** | `GygService`, `GygEmailParser`, `GygEmailClassifier`, `GygBookingApplicator`, `GygNotifier`, `GygPostBookingMailer`, `GygPickupResolver`, `Gyg/GygInquiryWriter`, `Gyg/GygTourProductMatcher` |
| **Commands** | `GygFetchEmails`, `GygProcessEmails`, `GygApplyBookings`, `GygReplayInboundEmails`, `GygPreviewBookingEmail`, `FixGygPrivateBookingPickup` |
| **Widgets** | `GygPipelineHealthWidget` |
| **Complexity** | **4/5** |

**Pain points:**
- 3-stage pipeline (fetch → process → apply) with separate state column `gyg_inbound_emails.status` — good pattern, but `FixGygPrivateBookingPickup` is a one-off data fix still in commands (should be removed).

### A9.3 Octobank (payment gateway)

| Aspect | Value |
|---|---|
| **Entrypoints** | `POST /octo/callback` · `GET /payment/success` |
| **Models** | none (uses `GuestPayment` + `BookingInquiry`) |
| **Services** | `OctoPaymentService` — 5-layer exchange-rate fallback |
| **Complexity** | **4/5** — money-critical |

**Pain points:**
- `OctoCallbackController@handle:63` and `:179` — **controller creates `GuestPayment` records directly** (two different sites inside one controller).
- Callback signature verification lives inside controller — Phase 3 to confirm integrity.

---

## A10. System / Admin Utilities

**Purpose:** cross-cutting: PDFs, language switching, feature-flag rollout, health.

| Aspect | Value |
|---|---|
| **Entrypoints** | `GET /healthz` · `GET /beds24/health` · `POST /language/switch` · Filament `UserResource`, `TagResource`, `LanguageSettings`, `TelegramApiDocs` |
| **Models** | `User`, `Tag`, `Zayavka`, `SysInstruction`, `Info`, `Bank`, `Turfirma` |
| **Services** | `StaticSitePageCache`, `ResponseFormatterService`, `OpenAIDateExtractorService`, `TurfirmaService` |
| **Jobs** | `GenerateBookingPdf`, `GenerateContractPdf` |
| **Commands** | `AssertProductionConfig`, `QueueHealthCheck`, `SeedTelegramBots`, `SetTelegramPosWebhook`, `SetBookingBotCommands`, `WebhookReplay` |
| **Complexity** | **2/5** |

---

## A11. Cross-domain fan-in summary

| Model | Domain | Service callers | Controllers touching | Filament touching |
|---|---|---:|---:|---:|
| `CashTransaction` | Financial | 15 | 3 | 1 |
| `Beds24Booking` | Integrations | 14 | 1 | 0 |
| `BookingInquiry` | Reservations | 13 | 2 | 5 |
| `CashierShift` | Financial | 13 | 1 | 1 |
| `Beds24PaymentSync` | Financial (via Beds24) | 8 | 0 | 0 |
| `Booking` | Reservations (legacy) | 7 | multiple | 2 |
| `TourProduct` | Tour catalog | 6 | 0 | 2 |
| `BookingFxSync` | Financial | 6 | 0 | 0 |
| `DailyExchangeRate` | Financial | 3 | 0 | 0 |
| `GuestPayment` | Financial | **0** | 1 | 3 |
| `SupplierPayment` | Financial | **0** | 0 | 2 |
| `Expense` | Financial | **0** | 0 | 0 |

**Key observation:** `GuestPayment`, `SupplierPayment`, `Expense`, `AgentPayment`, `DriverPayment`, `Accommodation` all have **zero service callers**. They are created and read **exclusively from controllers and Filament**. The application layer does not own them.

---

## A12. Domain overlap / boundary issues

| Issue | Domains involved | Evidence |
|---|---|---|
| **Dual booking aggregates** | Reservations | `Booking` (legacy) + `BookingInquiry` (new) both alive, both referenced across Filament |
| **Dual reconciliation** | Financial ∩ Beds24 | `ReconciliationService` + `Fx/WebhookReconciliationService` |
| **Task-like duplication** | Housekeeping | `RoomCleaning` + `RoomRepair` + `RoomIssue` — 3 parallel "work item" tables, no shared abstraction |
| **Keyboard services** | Bot infra | `TelegramKeyboardBuilder` + `TelegramKeyboardService` |
| **Bot payment service** | Bot infra ∩ Financial | `BotPaymentService` **duplicated** in `Services/` and `Services/Fx/` — both create `CashTransaction` records |
| **Tourfirm panel scope** | System / Tour catalog | Separate `/tourfirm` Filament panel holds only `ZayavkaResource`. Minimal, but creates a second auth surface |
| **Session state sprawl** | Bot infra ∩ Reservations | `TelegramPosSession`, `TelegramBookingSession`, `OperatorBookingSession` — 3 session models, no common contract |

---

# PART B — 🔴 FINANCIAL SYSTEM (super-domain)

Elevated per user directive. This is the ledger candidate. Everything below feeds Phase 3.5 (`MONEY_FLOW_DEEP_DIVE.md`) and Phase 4 (target architecture).

## B1. Why this is a super-domain

From Phase 1:
- Money is reachable through **9 cron jobs + 4 webhook paths + 3 bots + 10 Filament pages/widgets/resources**
- ~20 money-carrying models
- ~40 application-layer files touching money
- 10 distinct sites creating `CashTransaction` records
- Filament pages directly create `GuestPayment` and `SupplierPayment`
- 4 services exist in duplicate (`Services/` + `Services/Fx/`)

There is **no single model, service, or table that represents "a money event"** with full confidence.

## B2. All money entities (the raw picture)

### B2.1 Transaction-level tables
| Table | Role | Owner layer today |
|---|---|---|
| `cash_transactions` | Core ledger candidate — has 30+ columns post-2026-03 | Controllers + Filament + Services + Actions |
| `guest_payments` | Payments received from guests | Controllers + Filament + GYG service |
| `supplier_payments` | Payments to suppliers | Filament only |
| `agent_payments` | Payments to agents | — |
| `driver_payments` | Payments to drivers | — |
| `cash_expenses` | Cash-drawer expenses | `CashierExpenseService` |
| `expenses` (`Expense`) | General expense log | — |
| `tour_expenses` | Tour-specific expenses | — |

### B2.2 Shift / drawer tables
| Table | Role |
|---|---|
| `cashier_shifts` | Open/close cashier session, beginning/end saldo, approval lifecycle |
| `cash_drawers` | Physical drawer, location-scoped, balances |
| `cash_counts` | Mid/end-of-shift cash counts |
| `beginning_saldos`, `end_saldos` | Opening/closing balance per shift |

### B2.3 FX / rate tables
| Table | Role |
|---|---|
| `exchange_rates` | Ad-hoc rates |
| `daily_exchange_rates` | Daily CBU-anchored rates (preferred) |
| `booking_fx_syncs` | Booking ↔ FX rate snapshot |
| `beds24_payment_syncs` | Beds24 payment ↔ internal sync |
| `fx_manager_approvals` | Override approval chain |

### B2.4 Reconciliation / invoice tables
| Table | Role |
|---|---|
| `booking_payment_reconciliations` | Booking vs payment truth reconciliation |
| `invoices` | Invoicing |

### B2.5 Rate / cost tables (inputs to calculation)
| Table | Role |
|---|---|
| `accommodation_rates` | Nightly rates |
| `driver_rates` | Driver cost rates |
| `guide_rates` | Guide cost rates |
| `tour_prices`, `tour_price_tiers` | Tour pricing |

**Total: ~22 money-related tables.**

## B3. Entrypoints — every way money enters the system

| # | Entry | Surface | Creates / Mutates |
|---|---|---|---|
| 1 | `POST /octo/callback` | HTTP | `GuestPayment`, `BookingInquiry.payment_status` |
| 2 | `POST /beds24/webhook` | HTTP | `Beds24Booking`, `CashTransaction` (at `Beds24WebhookController:693`), `Beds24PaymentSync`, `BookingFxSync` |
| 3 | `POST /api/telegram/cashier/webhook` | Telegram bot | `CashTransaction` (at `CashierBotController:1065`), `CashierShift`, `CashExpense` |
| 4 | `POST /api/telegram/pos/webhook` | Telegram bot | `CashTransaction` (via `BotPaymentService`), `CashierShift` |
| 5 | `POST /api/telegram/owner/webhook` | Telegram bot | `CashTransaction` (at `OwnerBotController:141`) |
| 6 | Filament `CreateCashTransaction` page | Admin UI | `CashTransaction` (at `:64`) |
| 7 | Filament `TourCalendar` page | Admin UI | `GuestPayment` (at `:160`), `SupplierPayment` (at `:432`) |
| 8 | Filament `BookingInquiryResource` | Admin UI | `GuestPayment` (at `:1376`) |
| 9 | `GygInquiryWriter` service | GYG pipeline | `GuestPayment` (at `:132`) |
| 10 | `CashierExpenseService` | Service | `CashExpense`, `CashTransaction` |
| 11 | `CashierExchangeService` | Service | `CashTransaction` ×2 (the two sides of a currency exchange) |
| 12 | `RecordTransactionAction` | Action | `CashTransaction` (the one proper action — underused) |
| 13 | `BotPaymentService` + `Fx/BotPaymentService` | Services (**duplicate**) | `CashTransaction` |
| 14 | Cron `cash:reconcile` (`RunReconciliation`) | Scheduler | `BookingPaymentReconciliation` |
| 15 | Cron `cash:daily-report`, `cash:monthly-report` | Scheduler | Read-only reports (via `AdvancedReportService`) |
| 16 | Cron `fx:push-payment-options`, `fx:repair-*`, `fx:nightly-report`, `fx:expire-approvals` | Scheduler | `BookingFxSync`, `FxManagerApproval` |
| 17 | Cron `inquiry:send-payment-reminders` | Scheduler | mutates `BookingInquiry.payment_reminder_sent_at` |
| 18 | Beds24 `Beds24PaymentSyncJob` | Queue job | `Beds24PaymentSync`, `CashTransaction` |

**18 distinct surfaces** that create or mutate money state.

## B4. Where truth is STORED, MUTATED, CALCULATED

### Truth STORED

| What | Where (today) | Concern |
|---|---|---|
| Cash event | `cash_transactions` | Trying to be a ledger; conflates event + approval + presentation |
| Guest payment receipt | `guest_payments` | ⚠️ **Two schemas** — see B6 |
| Supplier payment | `supplier_payments` | New (Apr 2026) |
| Exchange rate of record | `daily_exchange_rates` | FK from `cash_transactions.daily_exchange_rate_id` |
| FX override approval | `fx_manager_approvals` | ⚠️ **Two Schema::create migrations** |
| Booking↔FX snapshot | `booking_fx_syncs` | ⚠️ **Two Schema::create migrations** |
| Shift balance | `cashier_shifts` | Denormalized expected/counted saldo |
| Drawer balance | `cash_drawers` | Location-scoped |
| Reconciliation outcome | `booking_payment_reconciliations` | Separate from `cash_transactions` |

### Truth MUTATED

| Who | File:line | Concern |
|---|---|---|
| `RecordTransactionAction` | `app/Actions/RecordTransactionAction.php:51, 66` | ✅ correct home |
| `Beds24WebhookController` | `app/Http/Controllers/Beds24WebhookController.php:693` | 🔴 controller |
| `OwnerBotController` | `app/Http/Controllers/OwnerBotController.php:141` | 🔴 controller |
| `CashierBotController` | `app/Http/Controllers/CashierBotController.php:1065` | 🔴 controller |
| `OctoCallbackController` | `app/Http/Controllers/OctoCallbackController.php:63, 179` | 🔴 controller (2 sites) |
| `CashierExpenseService` | `app/Services/CashierExpenseService.php:49` | 🟡 service — also creates `CashExpense` first |
| `CashierExchangeService` | `app/Services/CashierExchangeService.php:38, 54` | 🟡 service |
| `BotPaymentService` | `app/Services/BotPaymentService.php:179` | ⚠️ **duplicate** |
| `Fx/BotPaymentService` | `app/Services/Fx/BotPaymentService.php:149` | ⚠️ **duplicate** |
| `Filament\CreateCashTransaction` | `app/Filament/Resources/CashTransactionResource/Pages/CreateCashTransaction.php:64` | 🔴 Filament |
| `Filament\TourCalendar` | `app/Filament/Pages/TourCalendar.php:160, 432` | 🔴 Filament (Guest + Supplier Payment) |
| `Filament\BookingInquiryResource` | `app/Filament/Resources/BookingInquiryResource.php:1376` | 🔴 Filament (GuestPayment) |
| `Gyg/GygInquiryWriter` | `app/Services/Gyg/GygInquiryWriter.php:132` | 🟡 GYG pipeline |

**Summary: 13 distinct mutation sites.** Only 2 of them route through the `RecordTransactionAction` pattern.

### Truth CALCULATED

| Report / number | Where the math lives | Concern |
|---|---|---|
| Daily cash flow | `Filament/Pages/CashDashboard`, `Widgets/CashFlowChart`, `Widgets/CashTodayStats`, `Widgets/DrawerBalanceWidget` | 🔴 math in views |
| Guest outstanding balances | `Filament/Pages/GuestBalances` | 🔴 math in views |
| Supplier balances | `Filament/Pages/SupplierBalances` | 🔴 math in views |
| Expense reports | `Filament/Pages/ExpenseReports`, `Widgets/ExpenseChart` | 🔴 math in views |
| Advanced report | `Services/AdvancedReportService` (506 LOC) | 🟡 a service exists — but still ad-hoc |
| Bookings report | `Filament/Pages/BookingsReport` | 🔴 math in view |
| Dispatch totals | `Filament/Pages/TourCalendar` (725 LOC) | 🔴 math in view |
| Daily recap email/Telegram | `Services/DailyRecapBuilder` | ✅ service-owned |
| Reconciliation | `Services/ReconciliationService` + `Fx/WebhookReconciliationService` | ⚠️ split between two services |

**Verdict:** There is **no single "financial read model" service**. Numbers are computed fresh in each Filament view. Reports cannot be reproduced exactly because formulas vary across views.

## B5. Duplicate services — the Fx/ collision

Four service classes exist with identical basename in both `app/Services/` and `app/Services/Fx/`:

| Basename | Top-level LOC | `Fx/` LOC | Notes |
|---|---:|---:|---|
| `BotPaymentService` | ~800 (est) | **244** | **Both create `CashTransaction`**. Top-level version called by `TelegramPosController`; Fx/ version called from Fx pipeline. Diverged copies. |
| `FxManagerApprovalService` | — | **183** | Top-level has `Request $request` leak (§INVENTORY.md §7). Two copies = inconsistent approval behavior. |
| `FxSyncService` | — | **178** | Responsible for booking↔FX push. |
| `OverridePolicyEvaluator` | — | **76** | Decides when an override is allowed. |

**Risk:** future bug fixes patch only one copy; live dispatch chooses whichever is imported by the caller. **One of these classes is drifted dead code — we do not know which.** Phase 3 will declare a winner for each.

## B6. Migration integrity issues

Three tables have **two `Schema::create` migrations each**:

| Table | Migration 1 | Migration 2 | Schema delta |
|---|---|---|---|
| `guest_payments` | `2025_03_04_033652` | `2026_04_17_000002` | 🔴 **completely different**: v1 has `guest_id` + `booking_id`; v2 has `booking_inquiry_id` + refund semantics (positive/negative amount) |
| `booking_fx_syncs` | `2026_03_28_130000` | `2026_03_29_100001` | Similar shape, 24h apart — likely re-create after rename |
| `fx_manager_approvals` | `2026_03_28_130001` | `2026_03_29_100003` | Same |

For fresh installs the second migration will fail (`CREATE TABLE ... already exists`) unless the first silently drops it. Production has whichever ran first. This must be resolved in Phase 5 (P0).

## B7. Override / approval chain

`cash_transactions` carries its own approval workflow fields:
- `is_override`, `within_tolerance`, `variance_pct`
- `override_tier` (enum: none/cashier/manager/blocked)
- `override_reason`, `override_approved_by`, `override_approved_at`
- `override_approval_id` → FK to `fx_manager_approvals`

The approval lifecycle lives across:
- `FxManagerApprovalService` (🟡 has request-leak, in duplicate-service list)
- `OverridePolicyEvaluator` (in duplicate-service list)
- `ExpireManagerApprovals` command (cron: every 5 min)
- `FxNightlyExceptionReport` (cron: daily 08:30)

**Observation:** the override chain is a **separate sub-domain** with its own lifecycle. In target architecture it probably deserves its own bounded context under Financial (approvals-as-events).

## B8. First-pass ledger candidate outline

Not a design decision — an **input for Phase 4**.

The target is a single append-only `ledger_entries` table (or equivalent aggregate root) where:
- Every money event is one row
- Rows are **immutable** (no `update`, no `soft delete` on the event itself)
- Adjustments / reversals / refunds are **new rows** referencing the original
- Every row carries the context it needs to be reconstructable: shift, drawer, booking, guest, currency, rate, source trigger, created_by

Sketched row shape (for Phase 4 to debate):

```
ledger_entries
├── id
├── event_id (ULID — idempotency key)
├── occurred_at
├── recorded_at
├── source_trigger     (webhook | bot | cron | manual | filament | gyg | octo)
├── source_ref         (external id, e.g. Beds24 booking id, Octo payment id)
├── entry_type         (payment_in | payment_out | expense | exchange_leg | refund | adjustment | drawer_open | drawer_close | saldo_beginning | saldo_end)
├── counterparty_type  (guest | supplier | driver | guide | agent | bank | internal)
├── counterparty_id
├── booking_inquiry_id (nullable — links to reservation)
├── shift_id, drawer_id
├── amount, currency
├── related_amount, related_currency  (for exchange legs)
├── applied_rate, reference_rate, rate_source, rate_date
├── override_tier, override_approval_id  (chain lives separately)
├── presentation_snapshot_json (UZS / EUR / RUB / USD)
├── created_by_user_id, created_by_bot_slug
├── notes, tags_json
└── replaces_entry_id / reversed_by_entry_id
```

**Reporting becomes a query over this table**, not ad-hoc SQL in each Filament widget.

All existing tables (`cash_transactions`, `guest_payments`, `supplier_payments`, `cash_expenses`) would become **projections / read-models** derived from the ledger — or migrated into it.

**This is a sketch for the user to react to**, not a decision.

## B9. Dependencies that must be resolved before Phase 4 design

1. Pick a single `BotPaymentService` (top-level vs Fx/) — Phase 3 investigation.
2. Pick a single `FxManagerApprovalService` — same.
3. Resolve the 3 duplicate `Schema::create` migrations without data loss.
4. Decide the fate of legacy `Booking` model vs new `BookingInquiry`.
5. Decide whether `cash_transactions` becomes the ledger, is replaced, or is retired.
6. Decide whether Filament pages stop mutating money directly (likely yes).

---

# PART C — Summary

## C1. Domain clarity — current state

| Domain | Clear boundary? | Owns its models? | Owns its workflow? |
|---|---|---|---|
| A1. Reservations & Inquiries | 🟡 (dual aggregate) | 🔴 god-service + god-job | 🟡 |
| A2. Tour Catalog & Dispatch | 🟡 | 🟡 | 🔴 (Filament mutates money) |
| A3. Accommodation & Rooms | 🟡 (overlap with status) | ✅ | 🟡 |
| A4. Housekeeping | 🔴 (no service layer) | 🟡 (3 parallel task tables) | 🔴 (bot = domain) |
| A5. Kitchen | 🟡 | ✅ (1 model) | 🔴 (bot = domain) |
| A6. Guest & Ratings | ✅ | ✅ | ✅ |
| A7. Staff & Bot Infra | 🟡 (session sprawl) | ✅ | 🟡 |
| A8. Messaging | 🟡 (dead models) | ✅ | ✅ |
| A9. Integrations | 🟡 (split reconciliation) | ✅ | 🟡 |
| A10. System / Admin | ✅ | ✅ | ✅ |
| **B. Financial** | 🔴 **no boundary — scattered** | 🔴 **orphan models** | 🔴 **presentation mutates it** |

## C2. Three questions, directly answered

1. **Where is truth stored?** — Across 22 money tables. `cash_transactions` is the de facto ledger but is conflated with approvals and presentation snapshots.
2. **Where is truth mutated?** — 13 distinct sites across controllers, services, Filament pages, actions, jobs, commands. Only 2 sites use `RecordTransactionAction`.
3. **Where is truth calculated?** — Mostly in Filament pages and widgets. `AdvancedReportService` exists but is not the single source of financial math.

These answers make Phase 3 (VIOLATIONS) and Phase 3.5 (MONEY_FLOW_DEEP_DIVE) the priority.

## C3. New findings raised in Phase 2 (not in Phase 1)

1. 🔴 **3 tables with duplicate `Schema::create` migrations** (`guest_payments`, `booking_fx_syncs`, `fx_manager_approvals`)
2. 🔴 **13 mutation sites for money**, only 2 using the `Action` pattern
3. 🔴 **Money orphan models**: `GuestPayment`, `SupplierPayment`, `Expense`, `AgentPayment`, `DriverPayment` — zero service callers
4. 🟡 **Housekeeping has no service layer** — controller is the domain
5. 🟡 **3 parallel task-like tables** (`RoomCleaning`, `RoomRepair`, `RoomIssue`) — no shared abstraction
6. 🟡 **Session model sprawl** (`TelegramPosSession`, `TelegramBookingSession`, `OperatorBookingSession`)
7. 🟡 **Dead feature still in models** (`ScheduledMessage*`)

---

**Next phase:** Phase 3 — `VIOLATIONS.md` (layer leaks with `file:line` evidence) + Phase 3.5 — `MONEY_FLOW_DEEP_DIVE.md` (ledger design inputs).
