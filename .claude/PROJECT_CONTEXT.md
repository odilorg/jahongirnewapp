# Jahongir Travel App — Project Context

**Last Updated:** 2026-03-27
**Stack:** Laravel 11, Filament 3, Livewire, MySQL, Redis, Laravel Queues

---

## What This App Is

A comprehensive hospitality operations platform for a travel company in Uzbekistan.
Handles hotel bookings (via Beds24), tour management (via GetYourGuide), cash accounting,
8 Telegram bots for different staff roles, and an extensive Filament admin panel.

---

## 16 Feature Blocks

### 1. Booking Management
Core booking lifecycle — create, view, edit, reconcile payments.
- Models: `Booking`, `BookingDriver`, `BookingTour`, `BookingPaymentReconciliation`
- Services: `TelegramBookingService`, `BookingIntentParser`
- Filament: `BookingResource`, `BookingPaymentReconciliationResource`
- Jobs: `ProcessBookingMessage`

### 2. Guest & Guide Management
Guest profiles, guide assignments, post-tour flows (reviews, hotel requests, reminders).
- Models: `Guest`, `Guide`, `Rating`, `LostFoundItem`
- Filament: `GuestResource`, `GuideResource`, `GuestPaymentResource`, `RatingResource`
- Commands: `TourSendReminders` (daily 20:00), `TourSendReviewRequests` (10:00), `TourSendHotelRequests` (09:00)

### 3. Telegram Bot Infrastructure
8 bots with a shared transport/auth/audit framework.
- Bots: booking, POS terminal, cashier, owner alerts, housekeeping, kitchen, driver signup, availability
- Models: `TelegramBot`, `TelegramBotSecret`, `TelegramBotConversation`, `TelegramBotAccessLog`, `TelegramServiceKey`, `TelegramPosSession`
- Controllers: `TelegramController`, `TelegramPosController`, `CashierBotController`, `OwnerBotController`, `HousekeepingBotController`, `KitchenBotController`, `TelegramDriverGuideSignUpController`, `TelegramWebhookController`
- Services: `Telegram/BotResolver`, `Telegram/TelegramTransport`, `Telegram/BotSecretProvider`, `Telegram/BotAuditLogger`, `Telegram/LegacyConfigBotAdapter`, `TelegramPosService`, `TelegramKeyboardBuilder`
- Filament: `TelegramBotResource`, `TelegramServiceKeyResource`, `BotConfigurationResource`, `TelegramBotConversationResource`
- Routes: `POST /telegram/*/webhook`, `POST /internal/bots/{slug}/*`

### 4. Beds24 Integration (Hotel Booking Sync)
Syncs hotel availability and bookings with Beds24 PMS.
- Models: `Beds24Booking`, `Beds24BookingChange`, `Beds24WebhookEvent`
- Controllers: `Beds24WebhookController`
- Services: `Beds24BookingService`, `ReconciliationService`
- Commands: `RefreshBeds24Token` (every 20h), `RunReconciliation` (daily 21:00 + weekly), `SendDailyOwnerReport` (22:00)
- Jobs: `ProcessBeds24WebhookJob`
- Routes: `POST /beds24/webhook`, `GET /beds24/health`

### 5. GetYourGuide (GYG) Integration
Email-based booking ingestion pipeline — fetches GYG emails every 5 min and creates bookings.
- Models: `GygBooking`, `GygReservation`, `GygProduct`, `GygAvailability`, `GygInboundEmail`, `GygNotification`
- Controllers: `GygController`
- Services: `GygService`, `GygEmailParser`, `GygEmailClassifier`, `GygBookingApplicator`, `GygNotifier`, `GygPostBookingMailer`
- Commands: `GygFetchEmails`, `GygProcessEmails`, `GygApplyBookings` (all every 5 min), `GygPreviewBookingEmail`
- Routes: `GET|POST /gyg/1/*` (two route groups for compatibility)

### 6. Cash Management & Accounting
Shift-based cash accounting with daily/monthly reconciliation and reporting.
- Models: `CashTransaction`, `CashierShift`, `CashDrawer`, `CashExpense`, `CashCount`, `BeginningSaldo`, `EndSaldo`, `ExchangeRate`
- Controllers: `CashierBotController`
- Services: `CashierShiftService`, `CashierPaymentService`, `CashierExpenseService`, `CashierExchangeService`, `AdvancedReportService`
- Commands: `SendDailyCashReport` (23:00), `RunReconciliation` (21:00), `SendMonthlyCashReport` (1st of month 09:00)
- Filament: `CashTransactionResource`, `CashDrawerResource`, `CashierShiftResource`, `CashExpenseResource`
- Pages: `CashDashboard`
- Widgets: `CashTodayStats`, `CashFlowChart`, `DrawerBalanceWidget`

### 7. Expense Management
Tour and general expense tracking.
- Models: `Expense`, `ExpenseCategory`, `TourExpense`
- Filament: `ExpenseResource`, `TourExpenseResource`
- Pages: `ExpenseReports`
- Widgets: `ExpenseChart`

### 8. Driver & Transport Management
Driver profiles, car fleet, driver payments.
- Models: `Driver`, `Car`, `CarBrand`, `CarDriver`, `DriverPayment`, `SoldTourDriver`
- Filament: `DriverResource` (with Cars, TourExpenses, Bookings, Payments relation managers), `CarResource`, `CarBrandResource`

### 9. Accommodation Management
Hotel/room inventory, cleaning, repairs, utilities, availability checker.
- Models: `Hotel`, `Room`, `RoomType`, `RoomStatus`, `RoomCleaning`, `RoomRepair`, `RoomIssue`, `RoomUnitMapping`, `Location`, `Amenity`, `Utility`, `UtilityUsage`, `Meter`
- Filament: `HotelResource`, `RoomResource`, `RoomTypeResource`, `RoomRepairResource`, `LocationResource`, `AmenityResource`, `UtilityResource`, `UtilityUsageResource`, `MeterResource`
- Pages: `Availability`

### 10. Staff & Operations
Housekeeping, kitchen, shift management, terminal checks.
- Models: `Employee`, `Member`, `Partner`, `ShiftTemplate`, `ShiftHandover`, `TerminalCheck`
- Controllers: `HousekeepingBotController`, `KitchenBotController`
- Services: `KitchenGuestService` (meal count forecasting)
- Filament: `EmployeeResource`, `ShiftHandoverResource`, `TerminalCheckResource`

### 11. Communications & Notifications
Scheduled messages, WhatsApp/email delivery, contracts, language switching.
- Models: `Chat`, `ScheduledMessage`, `ScheduledMessageChat`, `Contract`
- Services: `Messaging/EmailSender`, `Messaging/WhatsAppSender`, `Messaging/GuestContactSender`, `TelegramReportService`
- Commands: `SendScheduledMessagesCommand` (every minute), `SendBookingNotification`
- Jobs: `SendTelegramMessageJob`, `SendTelegramNotificationJob`
- Filament: `ChatResource`, `ScheduledMessageResource`, `ContractResource`

### 12. Owner Alerts & Bot Analytics
Daily report bot, queue health monitoring.
- Models: `BotConfiguration`, `BotAnalytics`
- Controllers: `OwnerBotController`
- Services: `OwnerAlertService`
- Commands: `QueueHealthCheck` (every 5 min — alerts if jobs stuck >10 min)
- Filament: `BotConfigurationResource`
- Pages: `TelegramApiDocs`
- Widgets: `BotStatsOverview`

### 13. Payments (Octo)
Octo payment link generation with 5-layer exchange rate fallback.
- Models: `GuestPayment`, `DriverPayment`, `AgentPayment`, `Invoice`, `BookingPaymentReconciliation`
- Controllers: `OctoCallbackController`
- Services: `OctoPaymentService` — CBU → open.er-api.com → fawazahmed0 CDN → cache (6h) → OCTO_FALLBACK_USD_UZS_RATE
- Filament: `GuestPaymentResource`, `InvoiceResource`, `BookingPaymentReconciliationResource`
- Routes: `POST /octo/callback`, `GET /payment/success`

### 14. Reports & Analytics
Financial and operational dashboards.
- Services: `AdvancedReportService`
- Pages: `BookingsReport`, `ExpenseReports`, `Reports`
- Widgets: `StatsOverview`, `BotStatsOverview`, `CashFlowChart`, `ExpenseChart`
- Commands: `SendDailyOwnerReport`, `SendDailyCashReport`, `SendMonthlyCashReport`

### 15. Master Data & System Config
Reference data, tour firms, tour requests, AI instructions, language.
- Models: `User`, `Tag`, `Zayavka`, `SysInstruction`, `Info`, `IncomingWebhook`
- Filament: `UserResource`, `TagResource`, `TurfirmaResource`, `ZayavkaResource`, `AdminResource`, `AiInstructionResource`
- Pages: `LanguageSettings`
- Widgets: `CompactLanguageSwitcher`

### 16. System Utilities
PDF generation, webhook replay, queue monitoring, OpenAI date extraction.
- Services: `OpenAIDateExtractorService`, `ResponseFormatterService`
- Jobs: `GenerateBookingPdf`, `GenerateContractPdf`
- Commands: `WebhookReplay`, `AssertProductionConfig`, `QueueHealthCheck`, `SetTelegramPosWebhook`, `SeedTelegramBots`

---

## Scheduled Tasks

| Command | Schedule | Purpose |
|---------|----------|---------|
| `send-scheduled-messages` | Every 1 min | Send queued Telegram/WhatsApp messages |
| `gyg:fetch-emails` | Every 5 min | Fetch GYG booking emails |
| `gyg:process-emails` | Every 5 min | Classify & parse GYG emails |
| `gyg:apply-bookings` | Every 5 min | Create bookings from parsed emails |
| `queue:health-check` | Every 5 min | Alert owner if jobs stuck >10 min |
| `beds24:refresh-token` | Every 20 h | Refresh Beds24 API token |
| `tour:send-hotel-requests` | Daily 09:00 TZ | Hotel pickup requests for guests |
| `tour:send-review-requests` | Daily 10:00 TZ | Post-tour review requests |
| `cash:reconcile` | Daily 21:00 TZ | Reconcile bookings vs payments |
| `beds24:daily-report` | Daily 22:00 TZ | Owner booking/property report |
| `cash:daily-report` | Daily 23:00 TZ | Daily cash flow summary |
| `tour:send-reminders` | Daily 20:00 TZ | Reminders to staff, guests, drivers |
| `cash:reconcile --period=7d` | Weekly Sun 10:00 TZ | Full 7-day reconciliation |
| `cash:monthly-report` | 1st of month 09:00 TZ | Monthly cash report |

---

## API Routes Overview

```
POST  /telegram/webhook                  Main booking bot
POST  /telegram/driver_guide_signup      Driver/guide signup bot
POST  /telegram/bot/webhook              Availability bot
POST  /booking/bot/webhook               Booking bot (alt)
POST  /telegram/pos/webhook              POS terminal bot
POST  /telegram/cashier/webhook          Cashier bot
POST  /telegram/owner/webhook            Owner alerts bot
POST  /telegram/housekeeping/webhook     Housekeeping bot
POST  /telegram/kitchen/webhook          Kitchen bot
POST  /beds24/webhook                    Beds24 sync
GET   /beds24/health                     Beds24 health check
GET   /gyg/1/*                           GYG API (availability, reservations)
POST  /gyg/1/*                           GYG API (bookings, cancellations)
POST  /internal/bots/{slug}/*            Internal bot proxy API
POST  /octo/callback                     Payment callback
GET   /healthz                           App health check
```

---

## Key Numbers

| Category | Count |
|----------|-------|
| Models | 95 |
| Controllers | 16 |
| Services | 41+ |
| Filament Resources | 40+ |
| Custom Filament Pages | 8 |
| Dashboard Widgets | 8 |
| Console Commands | 20 |
| Background Jobs | 9 |
| Scheduled Tasks | 14 |
| Active Telegram Bots | 8 |
| API Endpoints | 40+ |

---

## Known Dead Code Removed (2026-03-27)

The following were audited and deleted (all confirmed zero references):
- `VoiceAgentController`, `VoiceFrontendController`, `LiveKitTokenController` — abandoned voice agent experiment
- `VoiceAgentAuth` middleware — never registered
- `resources/views/voice/agent.blade.php` — orphaned view
- `CarController`, `DriverController`, `GuestController`, `TourBookingController` — stub-only, no routes
- `TelegramMessageFormatter` service — no callers
- `WebhookService` service — abandoned n8n experiment
- `routes/api_fix.php` — never loaded by RouteServiceProvider

---

## VPS Deployment

```bash
# SSH
ssh jahongir  # alias in ~/.ssh/config → root@161.97.129.31 (jump host)

# App path
/var/www/jahongirnewapp

# Deploy
cd /var/www/jahongirnewapp
git pull origin main
php artisan config:clear
php artisan route:clear
php artisan cache:clear
```

## Important .env Keys

```
OCTO_SHOP_ID, OCTO_SECRET, OCTO_API_URL, OCTO_TSP_ID
OCTO_FALLBACK_USD_UZS_RATE=12100   # manual exchange rate floor
BEDS24_API_V2_TOKEN, BEDS24_API_V2_REFRESH_TOKEN
TELEGRAM_BOT_TOKEN, TELEGRAM_BOT_TOKEN_DRIVER_GUIDE
OWNER_ALERT_BOT_TOKEN, OWNER_TELEGRAM_ID
CASHIER_BOT_TOKEN, HOUSEKEEPING_BOT_TOKEN, KITCHEN_BOT_TOKEN
GYG_API_USERNAME, GYG_API_PASSWORD
REDIS_HOST, REDIS_PASSWORD
CACHE_DRIVER=file
```
