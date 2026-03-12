# Jahongir Hotel — System Documentation

> Complete documentation of all Telegram bot systems and Beds24 integrations.
>
> Last updated: 2026-03-12

---

## Table of Contents

1. [System Overview](#1-system-overview)
2. [Cashier / POS Bot](#2-cashier--pos-bot)
3. [Housekeeping Bot](#3-housekeeping-bot)
4. [Beds24 Webhook & Alerts](#4-beds24-webhook--alerts)
5. [User Roles & Access](#5-user-roles--access)
6. [Database Models](#6-database-models)
7. [Configuration & Environment](#7-configuration--environment)

---

## 1. System Overview

The system consists of three interconnected Telegram bots and a Beds24 PMS integration:

| Component | Purpose | Users |
|-----------|---------|-------|
| **Cashier/POS Bot** | Cash shift management, transactions, reports | Cashiers, Managers |
| **Housekeeping Bot** | Room status, issue reporting, lost & found | Cleaners, Managers |
| **Beds24 Webhooks** | Booking alerts, payment sync, checkout notifications | Owner (auto-alerts) |

**Tech Stack:** Laravel 11, Telegram Bot API, Beds24 API V2, Filament Admin Panel

**Properties:**
- Jahongir Hotel — Beds24 Property ID: `41097` (15 rooms)
- Jahongir Premium — Beds24 Property ID: `172793`

---

## 2. Cashier / POS Bot

### Overview

Telegram bot for hotel cashiers to manage cash shifts, record transactions, and generate financial reports. Supports multi-currency (UZS, USD, EUR, RUB) and multi-location operations.

**Webhook:** `POST /api/telegram/pos/webhook`
**Controller:** `TelegramPosController` (1,478 lines)
**Service:** `TelegramPosService`

### Authentication

1. User sends `/start` → Bot requests phone number
2. User shares contact → Bot matches phone against `users` table
3. Checks: user has role (`cashier`/`manager`/`super_admin`) + `pos_bot_enabled = true`
4. Session created in `telegram_pos_sessions` (15-min timeout, auto-extends)

### Cashier Features

#### Start Shift
- Assigns cash drawer automatically
- Records beginning saldo per currency
- Only one open shift per user allowed

#### Record Transaction
Multi-step conversational flow:
1. **Type** — Cash In / Cash Out / Currency Exchange
2. **Amount** — Numeric input
3. **Currency** — UZS, USD, EUR, RUB
4. **Category** — Sale, Refund, Expense, Deposit, Change, Other
5. **Notes** — Optional description

For currency exchange (IN_OUT): creates two linked transactions.

#### Close Shift
Multi-step flow per currency:
1. System shows expected balance (calculated from transactions)
2. Cashier counts physical cash and enters amount
3. Repeats for each currency used in shift
4. System calculates discrepancy (counted vs expected)
5. Shift marked as CLOSED with full audit trail

#### View My Shift
Shows: current balances per currency, transaction count, shift duration.

### Manager Features (Reports)

Managers see an extra "Reports" button with 9 report types:

| Report | Description |
|--------|-------------|
| Drawer Balances | Current balance in all cash drawers |
| Today Summary | Shifts, transactions, totals for today |
| Shift Performance | Detailed shift statistics |
| Transactions | Transaction activity for date range |
| Locations | Multi-location summary |
| Financial Range | Date range financial analysis |
| Discrepancies | Variance analysis (counted vs expected) |
| Executive Dashboard | High-level overview |
| Currency Exchange | Exchange transaction analysis |

### Session States

```
authenticated
  ├─ recording_transaction
  │   type → amount → currency → [out_amount → out_currency] → category → notes → save
  └─ closing_shift
      currency[0] amount → currency[1] amount → ... → close
```

### Key Files

| File | Purpose |
|------|---------|
| `app/Http/Controllers/TelegramPosController.php` | Webhook handler, all user flows |
| `app/Services/TelegramPosService.php` | Session management, authentication |
| `app/Services/TelegramKeyboardBuilder.php` | Keyboard layouts |
| `app/Services/TelegramReportService.php` | Report data collection |
| `app/Services/TelegramReportFormatter.php` | Report formatting (HTML) |
| `app/Services/AdvancedReportService.php` | Advanced report analytics |
| `app/Actions/StartShiftAction.php` | Shift opening logic |
| `app/Actions/CloseShiftAction.php` | Shift closing logic |
| `app/Actions/RecordTransactionAction.php` | Transaction recording |

---

## 3. Housekeeping Bot

### Overview

Telegram bot for hotel cleaners and managers to manage room cleaning status, report issues, track lost items, and coordinate urgent cleaning.

**Webhook:** `POST /api/telegram/housekeeping/webhook`
**Controller:** `HousekeepingBotController` (1,139 lines)
**Integration:** Beds24 API for real-time room status sync

### Authentication

1. User sends phone number via Telegram contact button
2. Bot matches last 9 digits against `users` table
3. Session created in `telegram_pos_sessions` table
4. Role determines keyboard: cleaner vs manager

### Keyboard Layout

**All staff:**
```
┌─────────────────────────────────┐
│       📊 Xonalar holati         │
├────────────────┬────────────────┤
│ 📸 Muammo      │ 🔴 Muammolar   │
│  yuborish      │                │
├────────────────┬────────────────┤
│ 📦 Topilma     │ 📢 Kam narsa   │
├────────────────┴────────────────┤
│          ❓ Yordam              │
├─────────────────────────────────┤
│         🚪 Chiqish              │
└─────────────────────────────────┘
```

**Managers get additional buttons:**
```
├────────────────┬────────────────┤
│ 🔴 TEZKOR      │ 📦 Topilmalar  │
├────────────────┬────────────────┤
│ 🟡 Xonani iflos│ 🟡 Hammasini   │
│                │    iflos       │
└────────────────┴────────────────┘
```

### Feature Details

#### 1. Room Status (📊 Xonalar holati)
- Pulls **live data from Beds24** via `getRoomStatuses()`
- Shows all 15 rooms with status emoji: ✅ Toza / 🟡 Iflos / 🔧 Ta'mirda
- Summary counts at bottom
- Inline buttons to quick-mark dirty rooms as clean

#### 2. Mark Room Clean
- Cleaner types room number(s): `7` or `3,5,11`
- Updates status to 'clean' in **Beds24** (two-way sync)
- Confirmation with cleaner name and timestamp

#### 3. Report Issue (📸 Muammo yuborish)
Multi-step flow:
1. Send photo of the issue
2. Enter room number (1-15)
3. Describe the issue (or /skip)
4. Photo downloaded and saved locally
5. `RoomIssue` record created (status: open, priority: medium)
6. **Management group** receives photo + caption alert

#### 4. Lost & Found (📦 Topilma)
Multi-step flow:
1. Send photo of found item
2. Enter room number where found
3. Describe the item (e.g., "soat", "telefon")
4. `LostFoundItem` record created (status: found)
5. **Management group** receives photo + alert

#### 5. Stock Alert (📢 Kam narsa)
Multi-step flow:
1. Enter room number
2. Enter item name (e.g., "sochiq", "shampun", "sovun")
3. **Management group** receives text alert
4. No DB record created (notification only)

#### 6. Rush Room — Manager only (🔴 TEZKOR)
1. Manager enters room + optional arrival time: `7 14:00`
2. **Broadcasts to ALL active cleaners:**
   ```
   🔴 TEZKOR TOZALASH!
   📍 7-xona tezkor tozalanishi kerak!
   👔 Buyurdi: Manager Name
   ⏰ Mehmon keladi: 14:00
   🧹 Iltimos, imkon qadar tez tozalang!
   ```

#### 7. Mark Room Dirty — Manager only (🟡 Xonani iflos)
- Enter room number → Updates Beds24 status to 'dirty'

#### 8. Mark All Dirty — Manager only (🟡 Hammasini iflos)
- Batch updates all non-dirty, non-repair rooms to 'dirty' in Beds24
- Used for daily reset

#### 9. View Issues (🔴 Muammolar)
- Lists all open issues with room, description, reporter, age
- Inline "✅ Hal qilish" button to resolve each issue

#### 10. View Lost & Found — Manager only (📦 Topilmalar)
- Lists last 20 found items (status: found)
- Shows room, description, finder name, age

### Session States

```
hk_main (default)
├─ hk_issue_photo → hk_issue_room → hk_issue_desc → save
├─ hk_lf_photo → hk_lf_room → hk_lf_desc → save
├─ hk_stock_room → hk_stock_item → notify
├─ hk_rush_room → broadcast
└─ hk_dirty_room → update Beds24
```

All flows support `/cancel` to return to main menu.

### Beds24 Room Status Integration

The housekeeping bot uses Beds24 as the **source of truth** for room statuses:

| Action | Beds24 API Call |
|--------|----------------|
| View status | `GET /properties?includeAllRooms=true&includeUnitDetails=true` |
| Mark clean | `POST /properties` with unit `statusText: 'clean'` |
| Mark dirty | `POST /properties` with unit `statusText: 'dirty'` |
| Mark all dirty | Batch `POST /properties` grouped by room type |

**Configured statuses in Beds24:**
- `clean` (color: #1578db)
- `dirty` (color: #000000)
- `repair` (color: #7A7474)

### Key Files

| File | Purpose |
|------|---------|
| `app/Http/Controllers/HousekeepingBotController.php` | All housekeeping flows |
| `app/Services/Beds24BookingService.php` | Beds24 API (room status methods) |
| `app/Models/RoomIssue.php` | Issue tracking model |
| `app/Models/LostFoundItem.php` | Lost & found model |

---

## 4. Beds24 Webhook & Alerts

### Overview

Beds24 sends booking events (create/modify/cancel) to our webhook. The system processes these into the local database, detects changes, and sends Telegram alerts to the hotel owner.

**Webhook:** `POST /api/beds24/webhook`
**Controller:** `Beds24WebhookController` (905 lines)
**Alert Service:** `OwnerAlertService`

### Alert Types

| Alert | Trigger | Recipient | Emoji |
|-------|---------|-----------|-------|
| **New Booking** | First received from Beds24 | Owner | 🟢 |
| **Cancellation** | Status → cancelled | Owner | 🔴 |
| **Cancellation After Check-in** | Cancelled but arrival ≤ today | Owner | 🔴 CRITICAL |
| **Modification** | Dates/room/guests changed | Owner | 🟡 |
| **Amount Reduced** | Total amount decreased | Owner | 🔴 CRITICAL |
| **Payment Received** | Invoice balance decreased | Owner | 💰 |
| **New Charge** | Charge item added (minibar, taxi) | Owner | 📝 |
| **Checkout** | CHECKOUT info code detected | All Cleaners | 🚪 |

### Processing Flow

```
Beds24 Event
    ↓
POST /api/beds24/webhook
    ↓
Parse payload (V1 flat / V2 JSON)
    ↓
┌─ New booking? → Create record → Alert owner
│                  └─ Pre-paid? → Create CashTransaction → Alert payment
│
└─ Existing? → Detect changes
               ├─ Checkout detected? → Notify all cleaners
               ├─ Cancelled? → Alert owner (critical if after check-in)
               ├─ Amount reduced? → Alert owner (critical)
               ├─ Modified? → Alert owner with changed fields
               ├─ Payment received? → Create CashTransaction → Alert owner
               └─ New charges? → Alert owner with charge details
```

### Payment Auto-Sync

When Beds24 reports a payment (invoice balance decreases):
1. Extract payment lines from `invoiceItems` (type: payment)
2. Deduplicate by reference: `"Beds24 #{bookingId} item#{itemId}"`
3. Create `CashTransaction` record (type: IN, category: SALE)
4. Link to active cashier shift
5. Alert owner with payment details

### Checkout → Cleaner Notification

When a guest checks out in Beds24:
1. Webhook payload contains `CHECKOUT` info code in `infoItems`
2. System compares with stored booking data to detect NEW checkouts
3. Resolves room number from Beds24 properties API (if missing)
4. Broadcasts to all authenticated housekeeping bot sessions:
   ```
   🚪 Checkout!
   📍 7-xona bo'shadi
   👤 Guest Name
   🧹 Tozalashni boshlang!
   ```

### Change Audit Trail

Every webhook creates a `Beds24BookingChange` record:
- `change_type`: created / cancelled / modified / amount_changed / payment_updated / charge_added / checked_out
- `old_data` / `new_data`: Full JSON snapshots
- Used for debugging and historical analysis

### Key Files

| File | Purpose |
|------|---------|
| `app/Http/Controllers/Beds24WebhookController.php` | Webhook processing, change detection |
| `app/Services/OwnerAlertService.php` | Telegram alerts to owner |
| `app/Services/Beds24BookingService.php` | Beds24 API (token mgmt, bookings, rooms) |
| `app/Models/Beds24Booking.php` | Booking record model |
| `app/Models/Beds24BookingChange.php` | Change audit trail model |

---

## 5. User Roles & Access

### Roles (Spatie Permission)

| Role | Filament Admin | POS Bot | Housekeeping Bot | Beds24 Alerts |
|------|---------------|---------|-------------------|---------------|
| `super_admin` | Full access | Manager features | Manager buttons | — |
| `admin` | Full access | Manager features | Manager buttons | — |
| `owner` | Full access | — | Manager buttons | — |
| `manager` | Limited | Manager features | Manager buttons | — |
| `cashier` | Limited | Cashier features | Basic buttons | — |
| `cleaner` | No access | No access | Basic buttons | — |

### Housekeeping Bot — Manager vs Cleaner

**Manager roles** (`super_admin`, `admin`, `owner`, `manager`):
- All cleaner features PLUS:
- 🔴 TEZKOR — Flag urgent room cleaning (broadcasts to all)
- 📦 Topilmalar — View lost & found list
- 🟡 Xonani iflos — Mark single room dirty
- 🟡 Hammasini iflos — Mark all rooms dirty

**Cleaner roles** (`cleaner`, `cashier`, or any other):
- 📊 Xonalar holati — View room statuses
- Type room numbers to mark clean
- 📸 Muammo yuborish — Report issue with photo
- 🔴 Muammolar — View open issues
- 📦 Topilma — Report found item
- 📢 Kam narsa — Report stock shortage

### Adding Users (Filament Admin)

1. Go to **Users Management → Users → Create**
2. Fill in: **Name**, **Email**, **Password**
3. **Roles** — Select role(s) from dropdown
4. **Phone Number** — Required for bot authentication (digits only, e.g., `998901234567`)
5. **POS Bot Access** toggle — Enable if user needs cashier bot
6. **Booking Bot Access** toggle — Enable if user needs booking bot
7. **Location Assignment** — Required for POS bot (which cash drawer to use)

The user then opens the relevant Telegram bot and sends their phone number to authenticate.

---

## 6. Database Models

### POS / Cashier System

| Model | Table | Purpose |
|-------|-------|---------|
| `CashierShift` | `cashier_shifts` | Shift records (open/close, balances, discrepancy) |
| `CashTransaction` | `cash_transactions` | Transaction log (in/out/exchange, multi-currency) |
| `CashDrawer` | `cash_drawers` | Physical cash drawers with balances |
| `CashExpense` | `cash_expenses` | Shift expenses with optional approval |
| `BeginningSaldo` | `beginning_saldos` | Multi-currency shift start balances |
| `EndSaldo` | `end_saldos` | Multi-currency shift end balances |
| `Location` | `locations` | Hotel locations/branches |
| `TelegramPosSession` | `telegram_pos_sessions` | Active bot conversations |
| `TelegramPosActivity` | `telegram_pos_activities` | Audit log |

### Housekeeping System

| Model | Table | Purpose |
|-------|-------|---------|
| `RoomIssue` | `room_issues` | Reported maintenance issues |
| `LostFoundItem` | `lost_found_items` | Found items tracking |

### Beds24 Integration

| Model | Table | Purpose |
|-------|-------|---------|
| `Beds24Booking` | `beds24_bookings` | Synced booking records |
| `Beds24BookingChange` | `beds24_booking_changes` | Change audit trail |

### Shared

| Model | Table | Purpose |
|-------|-------|---------|
| `User` | `users` | Staff accounts (Spatie roles, phone, bot flags) |

---

## 7. Configuration & Environment

### Required Environment Variables

```bash
# POS Bot
TELEGRAM_POS_BOT_TOKEN=xxx

# Housekeeping Bot
HOUSEKEEPING_BOT_TOKEN=xxx
HOUSEKEEPING_MGMT_GROUP_ID=xxx

# Owner Alerts Bot
OWNER_ALERT_BOT_TOKEN=xxx
OWNER_TELEGRAM_ID=xxx

# Beds24 API
BEDS24_API_TOKEN=xxx
BEDS24_API_V2_TOKEN=xxx
BEDS24_API_V2_REFRESH_TOKEN=xxx
```

### API Routes Summary

| Route | Method | Controller | Purpose |
|-------|--------|------------|---------|
| `/api/telegram/pos/webhook` | POST | TelegramPosController | POS bot webhook |
| `/api/telegram/pos/set-webhook` | POST | TelegramPosController | Set webhook URL (admin) |
| `/api/telegram/pos/webhook-info` | GET | TelegramPosController | Check webhook status (admin) |
| `/api/telegram/housekeeping/webhook` | POST | HousekeepingBotController | Housekeeping bot webhook |
| `/api/beds24/webhook` | POST | Beds24WebhookController | Beds24 booking events |

### Beds24 Token Management

The system uses a sophisticated token caching strategy:
- **Primary cache:** `beds24_access_token` (5-min early expiry)
- **Fallback cache:** `beds24_access_token_fallback` (longer TTL)
- **Refresh rotation:** Beds24 rotates refresh token on each use
- **Retry:** 3 attempts with exponential backoff (200ms, 1s, 3s)
- **Alert:** Owner notified if all refresh attempts fail

---

## Architecture Diagram

```
                    ┌──────────────┐
                    │   Beds24     │
                    │   (PMS)      │
                    └──────┬───────┘
                           │ Webhook (booking events)
                           ▼
┌─────────────────────────────────────────────────┐
│                  Laravel App                     │
│                                                  │
│  ┌─────────────┐  ┌──────────────┐  ┌────────┐ │
│  │ POS Bot     │  │ Housekeeping │  │ Beds24 │ │
│  │ Controller  │  │ Bot Controller│  │Webhook │ │
│  └──────┬──────┘  └──────┬───────┘  └───┬────┘ │
│         │                │               │      │
│         ▼                ▼               ▼      │
│  ┌─────────────┐  ┌──────────────┐  ┌────────┐ │
│  │ Shifts      │  │ Beds24       │  │ Owner  │ │
│  │ Transactions│  │ Room Status  │  │ Alert  │ │
│  │ Reports     │  │ Issues/L&F   │  │Service │ │
│  └─────────────┘  └──────────────┘  └────────┘ │
│                                                  │
│  ┌──────────────────────────────────────────┐   │
│  │         Filament Admin Panel              │   │
│  │   Users, Roles, Shifts, Reports, Config   │   │
│  └──────────────────────────────────────────┘   │
└─────────────────────────────────────────────────┘
         │              │              │
         ▼              ▼              ▼
    ┌─────────┐   ┌──────────┐   ┌─────────┐
    │Cashiers │   │ Cleaners │   │  Owner  │
    │(TG Bot) │   │ (TG Bot) │   │(TG Bot) │
    └─────────┘   └──────────┘   └─────────┘
```
