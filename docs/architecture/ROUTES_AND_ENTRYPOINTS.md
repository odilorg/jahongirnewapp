# ROUTES & ENTRYPOINTS — Jahongir Hotel Operations System

**Phase:** 1 · **Generated:** 2026-04-18 · **Commit:** `09ade0f`

Every way the application can be triggered. Four sections per `AUDIT_BRIEF.md` §5:
1. Web / API routes (HTTP)
2. Telegram / webhook entrypoints
3. Filament resources, pages, widgets (2 panels)
4. Console / scheduler / jobs

> **Why all four:** in this codebase the scheduler and jobs carry significant business logic. Mapping HTTP alone would miss money reconciliation, email ingestion, and reminders.

---

## 1. Web & API routes

Source files: `routes/web.php` (158 LOC), `routes/api.php` (198 LOC).

### 1.1 `routes/web.php` — active routes

| Method | Path | Controller@method | Middleware | Purpose |
|---|---|---|---|---|
| GET | `/` | *(closure)* | — | Redirect → `/admin/login` |
| GET | `/login` | *(closure)* | — | Redirect → Filament admin login |
| POST | `/language/switch` | `LanguageController@switch` | `web` | UI locale toggle |
| POST | `/octo/callback` | `OctoCallbackController@handle` | — | **💰 Octobank payment gateway callback** |
| GET | `/payment/success` | `OctoCallbackController@success` | — | Payment success redirect |
| GET+POST | `/1/*` (6 endpoints) | `GygController@*` | `gyg.auth` | GYG Supplier API (no `/api` prefix) — duplicate of §1.2 group |

### 1.2 `routes/api.php` — active routes

| Method | Path | Controller@method | Middleware | Purpose |
|---|---|---|---|---|
| GET | `/user` | *(closure)* | `auth:sanctum` | Current user |
| POST | `/availability` | `Availability@checkAvailability` *(Filament Page as API)* | `auth:sanctum` | ⚠️ **Filament Page used as API endpoint** — unusual coupling |
| POST | `/telegram/webhook` | `TelegramController@handleWebhook` | `verify.telegram.webhook:main` | Main booking bot |
| POST | `/telegram/driver_guide_signup` | `TelegramDriverGuideSignUpController@handleWebhook` | `verify.telegram.webhook:driver-guide` | Driver/guide signup bot |
| POST | `/webhook/tour-booking` | `WebhookController@handleTourBooking` | — | Generic tour-booking webhook |
| POST | `/bookings/website` | `WebsiteBookingController@store` | `website.api_key`, `throttle:30,1` | Legacy website-booking form (marked "not called") |
| POST | `/v1/inquiries` | `Api\BookingInquiryController@store` | `throttle:10,1` | **Active** website inquiry pipeline (replaces legacy above) |
| POST | `/telegram/bot/webhook` | `TelegramWebhookController@handle` | `verify.telegram.webhook:booking` | Availability bot |
| POST | `/telegram/bot/set-webhook` | `TelegramWebhookController@setWebhook` | `auth:sanctum` | Admin utility |
| GET | `/telegram/bot/webhook-info` | `TelegramWebhookController@getWebhookInfo` | `auth:sanctum` | Admin utility |
| POST | `/telegram/ops/webhook` | `OpsBotController@webhook` | — ⚠️ no `verify.telegram.webhook` | Ops bot (operator manual booking) |
| POST | `/booking/bot/webhook` | `BookingWebhookController@handle` | `verify.telegram.webhook:booking` | Alt booking bot |
| POST | `/telegram/pos/webhook` | `TelegramPosController@handleWebhook` | `verify.telegram.webhook:pos` | **💰 POS terminal bot** |
| POST | `/telegram/pos/set-webhook` | `TelegramPosController@setWebhook` | `auth:sanctum` | Admin utility |
| GET | `/telegram/pos/webhook-info` | `TelegramPosController@getWebhookInfo` | `auth:sanctum` | Admin utility |
| POST | `/beds24/webhook` | `Beds24WebhookController@handle` | ⚠️ **none** *(documented as "Beds24 calls without credentials")* | Beds24 booking sync |
| POST | `/telegram/cashier/webhook` | `CashierBotController@handleWebhook` | `verify.telegram.webhook:cashier` | **💰 Cashier bot** |
| POST | `/telegram/owner/webhook` | `OwnerBotController@handleWebhook` | `verify.telegram.webhook:owner-alert` | Owner alerts bot |
| POST | `/telegram/housekeeping/webhook` | `HousekeepingBotController@handleWebhook` | `verify.telegram.webhook:housekeeping` | Housekeeping bot |
| POST | `/telegram/kitchen/webhook` | `KitchenBotController@handleWebhook` | `verify.telegram.webhook:kitchen` | Kitchen bot |
| GET | `/healthz` | *(closure)* | — | Health + Redis + git SHA |
| GET | `/beds24/health` | *(closure, uses `Beds24BookingService`)* | — | Beds24 token status |
| `GET\|POST` | `/gyg/1/{6 endpoints}` | `GygController@*` | `gyg.auth` | GYG Supplier API — availability / reserve / cancel / book / notify |
| POST | `/internal/bots/{slug}/*` (6 endpoints) | `Api\InternalBotController@*` | `service.key`, `throttle:60,1` | Internal cross-app Telegram proxy |

### 1.3 Unused / commented-out routes

Both route files contain **large commented blocks** — n8n proxy, legacy webhook, dispatch-job tests. Dead weight to clean (Phase 5, P2).

### 1.4 Legacy / quarantined

- `Route::post('/bookings/website', ...)` — comment says *"LEGACY — kept for reference, not called"*. Candidate for deletion.
- `/voice-agent/*` group — all 3 inner routes removed; outer `Route::prefix('voice-agent')` left behind with empty group (lines 92–96 `api.php`). Also `VoiceAgentController.php.backup` exists. Dead feature.

### 1.5 Security observations (raised, not decided)

- `/beds24/webhook` has **no signature verification**. Documented but a risk vector. Phase 3 follow-up.
- `/telegram/ops/webhook` **has no Telegram signature middleware** while all other `/telegram/*` endpoints do. Inconsistent.
- `/webhook/tour-booking` has no middleware listed. Check handler.

---

## 2. Telegram / webhook entrypoints (8 bots)

| Bot | Webhook path | Controller | LOC | Status |
|---|---|---|---:|---|
| **Main booking bot** | `/api/telegram/webhook` | `TelegramController` | 592 | 🔴 Eloquent-in-controller |
| **Driver/Guide signup** | `/api/telegram/driver_guide_signup` | `TelegramDriverGuideSignUpController` | 1,061 | 🔴 god-controller |
| **Availability bot** | `/api/telegram/bot/webhook` | `TelegramWebhookController` | (small) | — |
| **Ops bot** | `/api/telegram/ops/webhook` | `OpsBotController` | — | ⚠️ no signature middleware |
| **Booking bot (alt)** | `/api/booking/bot/webhook` | `BookingWebhookController` | — | possibly redundant with main |
| **POS terminal** 💰 | `/api/telegram/pos/webhook` | `TelegramPosController` | 1,492 | 🔴 **god-controller in money domain** |
| **Cashier** 💰 | `/api/telegram/cashier/webhook` | `CashierBotController` | 1,819 | 🔴🔴 **biggest god-controller, money domain** |
| **Owner alerts** | `/api/telegram/owner/webhook` | `OwnerBotController` | — | — |
| **Housekeeping** | `/api/telegram/housekeeping/webhook` | `HousekeepingBotController` | 1,848 | 🔴 god-controller |
| **Kitchen** | `/api/telegram/kitchen/webhook` | `KitchenBotController` | 727 | 🔴 fat controller |

### 2.1 Non-Telegram webhooks

| Entrypoint | Path | Handler | Security |
|---|---|---|---|
| **Beds24** | `POST /api/beds24/webhook` | `Beds24WebhookController@handle` (1,189 LOC) | 🔴 no auth, no signature |
| **Octobank** 💰 | `POST /octo/callback` | `OctoCallbackController@handle` | — (presumably signature-verified inside handler — verify in Phase 3) |
| **GYG inbound email** | via `gyg:fetch-emails` cron (not HTTP) | `GygFetchEmails` command → `GygService` | IMAP credentials |
| **Legacy tour-booking** | `POST /api/webhook/tour-booking` | `WebhookController@handleTourBooking` | ⚠️ no middleware |
| **Internal bot proxy** | `/api/internal/bots/{slug}/*` | `Api\InternalBotController` | `service.key` header |

### 2.2 Notable Telegram middleware

- `verify.telegram.webhook:<bot-slug>` — custom middleware, per-bot. Likely in `app/Http/Middleware/` — Phase 3 to audit.
- 1 bot (`ops`) bypasses it.

---

## 3. Filament presentation layer

The app runs **two Filament panels** (a fact absent from `PROJECT_CONTEXT.md`).

### 3.1 Panels

| Panel ID | URL path | Provider | Purpose |
|---|---|---|---|
| `admin` | `/admin` | `app/Providers/Filament/AdminPanelProvider.php` | Main operations admin |
| `tourfirm` | `/tourfirm` | `app/Providers/Filament/TourfirmPanelProvider.php` | Separate tour-firm-scoped panel (resources in `app/Filament/Tourfirm/`) |

### 3.2 Admin panel resources (34 resources)

Ordered by domain. Every resource is a Filament CRUD at `/admin/{plural-kebab}`.

**Booking / guest / tour:**
`BookingInquiryResource` · `BookingPaymentReconciliationResource` · `GuestPaymentResource` · `RatingResource` · `TourProductResource` · `TurfirmaResource` · `ContractResource` · `InvoiceResource`

**Accommodation / hotel:**
`AccommodationResource` · `HotelResource` · `RoomResource` · `RoomTypeResource` · `RoomRepairResource` · `LocationResource` · `AmenityResource` · `UtilityResource` · `UtilityUsageResource` · `MeterResource`

**Cashier / money 💰:**
`CashDrawerResource` · `CashTransactionResource` · `CashierShiftResource` · `CashExpenseResource` · `ExpenseResource` · `SupplierPaymentResource`

**People / transport:**
`DriverResource` · `CarResource` · `EmployeeResource` · `GuideResource` · `UserResource` · `ShiftHandoverResource`

**Telegram / bots:**
`BotConfigurationResource` · `TelegramBotConversationResource` · `TelegramBotResource` · `TelegramServiceKeyResource`

**Other:**
`TagResource`

### 3.3 Admin panel pages (10)

| Page | Purpose | LOC |
|---|---|---:|
| `Availability` | Real-time room availability checker | — |
| `BookingsReport` | Booking analytics report | — |
| `CashDashboard` 💰 | Cash flow dashboard | — |
| `ExpenseReports` 💰 | Expense reporting | — |
| `GuestBalances` 💰 | Guest outstanding balances |  not-in-PROJECT_CONTEXT |
| `SupplierBalances` 💰 | Supplier balances | not-in-PROJECT_CONTEXT |
| `LanguageSettings` | UI locale admin | — |
| `Reports` | General reports | 680 |
| `TelegramApiDocs` | Internal bot API docs | — |
| `TourCalendar` | Booking/tour calendar w/ dispatch zones | 725 |

### 3.4 Admin panel widgets (8)

`BotStatsOverview` · `CashFlowChart` 💰 · `CashTodayStats` 💰 · `CompactLanguageSwitcher` · `DrawerBalanceWidget` 💰 · `ExpenseChart` 💰 · `GygPipelineHealthWidget` · `StatsOverview`

### 3.5 Tourfirm panel

Separate panel at `/tourfirm`. Resources under `app/Filament/Tourfirm/` — smaller scope, likely for external tour-firm users. **Needs Phase 2 deep check** to understand access model and overlap with admin resources.

### 3.6 Archived Filament

`app/Filament/_archived/` contains **40 files** — 5 retired Resources (Booking, Chat, Guest, ScheduledMessage, TerminalCheck, Tour, TourExpense, Zayavka) with their pages/relation managers. Still in the tree, still counted in Filament LOC. Delete candidate (Phase 5).

---

## 4. Console / scheduler / jobs entrypoints

### 4.1 Scheduled commands (from `app/Console/Kernel.php`)

Runs on VPS cron. **All times in `Asia/Tashkent` unless noted.**

| Cron/Schedule | Command | Purpose | Domain |
|---|---|---|---|
| Every 1 min | `queue:health-check` | Alert owner if jobs stuck >10 min | Ops |
| Every 5 min | `fx:expire-approvals` | Expire stale FX manager approvals | 💰 FX |
| Every 5 min | `fx:repair-stuck-syncs` | Re-dispatch stuck FX syncs | 💰 FX |
| Every 15 min | `inquiry:send-reminders` | Operator reminder emails | Inquiry |
| Every 15 min | `supplier:ping-imminent-tours` | T-1h driver/guide ping | Dispatch |
| Every 15 min | `gyg:fetch-emails` | Fetch GYG booking emails | GYG |
| Every 15 min | `gyg:process-emails` | Parse + classify GYG emails | GYG |
| Every 15 min | `gyg:apply-bookings` | Materialize bookings from GYG emails | GYG |
| Hourly | `inquiry:send-payment-reminders` | 💰 Payment reminder to guests | 💰 Inquiry/money |
| Daily 07:00 | `fx:push-payment-options` | Fetch CBU rates, push payment options | 💰 FX |
| Daily 07:15 | `fx:repair-missing --days=30` | Backfill missing FX syncs | 💰 FX |
| Daily 07:45 | `beds24:repair-failed-syncs` | Beds24 resync | Beds24 |
| Daily 07:50 | `beds24:repair-missing-syncs` | Beds24 backfill | Beds24 |
| Daily 08:30 | `fx:nightly-report` | Nightly FX exceptions report | 💰 FX |
| Daily 09:00 | `tour:send-hotel-requests` | Ask guests for hotel pickup | Tour |
| Daily 10:00 | `tour:send-review-requests` | Post-tour review emails | Tour |
| Daily 19:00 | `recap:send-daily` | Operator recap via Telegram + email | Reporting |
| Daily 20:00 | `tour:send-reminders` | Staff/guest/driver reminders | Tour |
| Daily 21:00 | `cash:reconcile` | 💰 Reconcile today's bookings vs payments | 💰 Money |
| Daily 22:00 | `beds24:daily-report` | Owner booking/property summary | Reporting |
| Daily 23:00 | `cash:daily-report` | 💰 Daily cash flow summary | 💰 Money |
| Weekly Sun 10:00 | `cash:reconcile --period=7d` | 💰 7-day reconciliation | 💰 Money |
| Monthly 1st 09:00 | `cash:monthly-report` | 💰 Monthly cash report | 💰 Money |
| Every 20h (cron) | `beds24:refresh-token` | Beds24 API token refresh | Beds24 |

**Total: 24 recurring schedules.** Money-domain crons alone: **9**.

### 4.2 All console commands (43 files — non-scheduled included)

Commands **not** in scheduler — invoked manually or on deploy:

| Command | Purpose |
|---|---|
| `AssertProductionConfig` | Deploy-time config guard |
| `Beds24Setup` | Interactive Beds24 bootstrap |
| `BotOperatorCommand` | Bot operator management |
| `CalculateAndPushDailyPaymentOptions` | 💰 FX rate helper (302 LOC) |
| `ClearExpiredPosSessions` | POS session cleanup |
| `ExpireManagerApprovals` | 💰 FX approval lifecycle |
| `ExportToursWebsiteData` | Static tour export |
| `ExportToursWebsitePdfs` | Tour PDF export |
| `FixGygPrivateBookingPickup` | One-off GYG data fix |
| `FxNightlyExceptionReport` | 💰 FX exceptions (scheduled via `fx:nightly-report` alias) |
| `GygPreviewBookingEmail` | Dev/debug preview |
| `GygReplayInboundEmails` | GYG email replay |
| `ImportToursFromStatic` | Tour catalog import (**717 LOC** — god-command) |
| `PingImminentTours` | Alias or alt form of `supplier:ping-imminent-tours` |
| `RefreshBeds24Token` | Scheduled (every 20h) |
| `RepairFailedBeds24Syncs`, `RepairMissingBeds24Syncs`, `RepairMissingFxSyncs`, `RepairStuckBeds24Syncs` | Data-repair family |
| `RollbackTourPricingLoader`, `RolloutTourPricingLoader` | Feature-flag migration helpers |
| `RunReconciliation` | 💰 Manual reconciliation trigger |
| `SeedTelegramBots` | Deploy-time seed |
| `SendBookingNotification` | Manual notification |
| `SendDailyCashReport`, `SendDailyOwnerReport`, `SendDailyRecap`, `SendMonthlyCashReport` | 💰 Report commands (scheduled) |
| `SendScheduledMessagesCommand` | Scheduled messages runner (scheduler **disabled 2026-04-15** per code comment — feature deprecated) |
| `SetBookingBotCommands`, `SetTelegramPosWebhook` | Deploy-time Telegram setup |
| `SurfaceFailedBeds24Syncs` | Ops tool |
| `TourSendHotelRequests`, `TourSendReminders`, `TourSendReviewRequests` | Tour notifications (scheduled) |
| `WebhookReplay` | Dev tool |
| `InquirySendPaymentReminders`, `InquirySendReminders` | Inquiry reminder commands |
| `GygApplyBookings`, `GygFetchEmails`, `GygProcessEmails` | GYG pipeline (scheduled) |
| `QueueHealthCheck` | Queue alert (scheduled) |

**Observation:** commands carry meaningful business logic (e.g. `ImportToursFromStatic` 717 LOC, `TourSendReminders` 551 LOC, `CalculateAndPushDailyPaymentOptions` 302 LOC). These are presentation-layer entrypoints to workflows that should live in services/actions — not in commands. Phase 3 violation candidates.

### 4.3 Jobs (11 files)

| Job | LOC | Dispatched from | Purpose |
|---|---:|---|---|
| `ProcessBookingMessage` | **1,025** | Telegram booking bot (likely) | 🔴 **god-job** — booking intent parsing |
| `ProcessTelegramMessage` | 281 | Telegram webhook controllers | Message handler |
| `GenerateContractPdf` | 120 | Contract flows | PDF generation |
| `Beds24PaymentSyncJob` | 113 | FX sync pipeline | 💰 Beds24 payment sync |
| `SendTelegramNotificationJob` | 105 | Various notifiers | Async Telegram send |
| `ProcessTelegramUpdateJob` | 98 | Telegram webhooks | Update router |
| `ProcessBeds24WebhookJob` | 96 | `Beds24WebhookController` | Async webhook processing |
| `SendTelegramMessageJob` | 81 | Messaging | Simple async send |
| `FxSyncJob` | 74 | FX pipeline | 💰 FX record sync |
| `GenerateBookingPdf` | 54 | Booking flows | PDF generation |
| `TestJob` | 24 | — | Dev placeholder (consider deletion) |

**Observation:** `ProcessBookingMessage` alone is 1,025 LOC — this is a workflow masquerading as a job. Should be refactored into orchestration service + multiple single-use-case actions.

### 4.4 Observers (6)

| Observer | Watches | Notes |
|---|---|---|
| `BookingObserver` | `Booking` | — |
| `BookingInquiryObserver` | `BookingInquiry` | Active core entity |
| `InquiryStayObserver` | Inquiry-stay join | — |
| `TourProductObserver` | `TourProduct` | Phase 8 tour catalog |
| `TourProductDirectionObserver` | Direction | Phase 8 |
| `TourPriceTierObserver` | Pricing tiers | Phase 8 |

Observers are valid presentation-layer hooks but can become hidden business logic — Phase 3 to check they only fire events, not mutate cross-domain state.

---

## 5. Summary — entrypoint surface

| Type | Count | Notes |
|---|---:|---|
| HTTP routes (public/active) | ~25 | 2 legacy + several commented blocks to remove |
| Telegram webhook endpoints | 10 | 1 bypasses signature middleware |
| Non-Telegram webhooks | 2 | Beds24 (no auth), Octo (presumed signature-in-handler) |
| Filament panels | **2** (admin, tourfirm) | Tourfirm absent from PROJECT_CONTEXT |
| Filament resources | 34 (admin) + N (tourfirm) | — |
| Filament pages | 10 (admin) | 4 are money-critical dashboards |
| Filament widgets | 8 | 4 money widgets |
| Scheduled commands | **24** | **9 money-related** |
| Other console commands | ~19 | Several fat (>200 LOC) |
| Queued jobs | 11 | 1 god-job (`ProcessBookingMessage`) |
| Observers | 6 | — |

**Top risks to carry into Phase 2/3:**

1. **Money is reached through ≥ 9 cron jobs + 4 webhook paths + 3 bots** — no single choke-point. Validates the ledger-core direction.
2. **2 webhook endpoints without integrity verification** (`/beds24/webhook`, `/telegram/ops/webhook`, `/webhook/tour-booking`).
3. **Tourfirm panel is a second authentication surface** — permissions and resource overlap need explicit Phase 2 treatment.
4. **Commands doing workflow work** — real application-layer logic living in `app/Console/Commands/`.

---

**Next phase:** Phase 2 — `DOMAINS.md`.
