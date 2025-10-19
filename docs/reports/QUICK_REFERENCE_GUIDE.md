# POS System - Quick Reference Guide

## Overview
A Laravel-based Telegram bot-integrated Point of Sale system for managing cashier shifts, transactions, and generating reports across multiple locations with multi-currency support (UZS, USD, EUR).

## Key Files
- **Models**: Located in `/app/Models/`
  - `CashierShift.php` - Main shift model (provided)
  - `CashTransaction.php` - Individual transactions
  - `CashDrawer.php` - Cash drawer locations
  - `User.php` - Users/cashiers
  - `Location.php` - Physical locations
  
- **Services**: Located in `/app/Services/`
  - `TelegramReportService.php` - Report generation (provided)
  - `TelegramReportFormatter.php` - Report formatting (provided)
  - `TelegramKeyboardBuilder.php` - Bot keyboard UI
  
- **Controllers**: Located in `/app/Http/Controllers/`
  - `TelegramPosController.php` - Webhook handler (provided)

## Core Entities

### CashierShift
Central model representing a cashier's work session.

**States:**
- `OPEN` - Shift in progress
- `CLOSED` - Shift ended, requires review
- `UNDER_REVIEW` - Flagged for manager approval (if discrepancy exists)

**Key Fields:**
| Field | Type | Purpose |
|-------|------|---------|
| status | enum | Current shift state |
| beginning_saldo | decimal | Starting cash (UZS) |
| expected_end_saldo | decimal | Calculated ending balance |
| counted_end_saldo | decimal | Actual cash counted |
| discrepancy | decimal | counted - expected |
| opened_at | datetime | Shift start |
| closed_at | datetime | Shift end |
| approved_at | datetime | When approved |
| rejected_at | datetime | When rejected |

### CashTransaction
Individual cash movements.

**Types:**
- `IN` - Cash inflow (sales, deposits)
- `OUT` - Cash outflow (payouts, expenses)  
- `IN_OUT` - Exchange (currency swap)

**Fields:**
- `type` - Transaction type (enum)
- `amount` - Transaction value
- `currency` - UZS, USD, EUR, etc.
- `category` - Classification
- `notes` - Description
- `occurred_at` - When it happened

---

## Current Reports (5 Available)

### 1. Today's Summary (`getTodaySummary()`)
High-level overview of daily operations.

**Returns:**
```
- Shift counts (open, closed, under review)
- Transaction counts by type
- Currency totals (cash in, out, net)
- Active cashier count
- Top performer (by transactions)
```

**Time Period:** Today only

---

### 2. Shift Performance (`getShiftPerformance()`)
Individual shift metrics for a date.

**Returns:**
```
- Per-shift breakdown:
  - Duration
  - Transaction count
  - Currency balances
  - Status
  
- Daily aggregates:
  - Total shifts
  - Total transactions
  - Average duration
```

**Time Period:** Specific date (defaults to today)

---

### 3. Shift Detail (`getShiftDetail()`)
Deep dive into single shift.

**Returns:**
```
- Shift metadata (cashier, drawer, location)
- Transaction breakdown by type
- Last 20 transactions
- Currency balances
- Approval status
```

**Time Period:** Single shift

---

### 4. Transaction Activity (`getTransactionActivity()`)
Transaction-level analysis across date range.

**Returns:**
```
- Total transactions and amount
- Breakdown by category
- Breakdown by currency (with in/out/net)
- Top 5 cashiers by volume
- Transaction type counts
```

**Time Period:** Configurable date range (currently used as today only)

---

### 5. Multi-Location Summary (`getMultiLocationSummary()`)
Compare performance across locations.

**Returns:**
```
Per location:
- Shift counts
- Transaction counts and totals
- Active cashier count

For managers with multiple locations
```

**Time Period:** Today only

---

## Authorization & Access

**Roles:**
- `super_admin` - All data
- `manager` - Assigned locations only
- `cashier` - No report access

**Location Filtering:**
- Managers see only their assigned locations' shifts
- Super admins see everything

---

## Supported Currencies

**Primary:** UZS (Uzbek Som)
**Secondary:** USD, EUR, and potentially others

**Multi-Currency Behavior:**
- Each transaction tagged with one currency
- Separate tracking via `BeginningSaldo` and `EndSaldo` tables
- Balance calculated per-currency
- No automatic exchange rates

---

## Available Data for Reporting

### Financial Metrics
- Beginning/ending balances (per currency)
- Cash in/out totals
- Discrepancies (counted vs. expected)
- Net balances per currency
- Category-wise amounts

### Time Data
- Shift duration (hours/minutes)
- Transaction timestamps
- Approval/rejection timestamps
- Peak hours analysis

### User/Cashier Data
- Active cashiers count
- Top performers (by volume)
- Transaction volume per cashier
- Shift patterns
- Accuracy (discrepancy frequency)

### Location Data
- Shifts per location
- Transactions per location
- Active staff per location
- Location comparison

### Workflow Data
- Approval status
- Rejection reasons
- Approval/rejection rates
- Discrepancy tracking

---

## Enums

### ShiftStatus
```
OPEN, CLOSED, UNDER_REVIEW
```

### TransactionType
```
IN, OUT, IN_OUT
```

### Currency
```
UZS, USD, EUR (+ others via cases())
```

---

## Key Calculations

### Expected End Saldo
```
= Beginning Saldo 
  + SUM(IN transactions) 
  + SUM(IN_OUT transactions)
  - SUM(OUT transactions)
```

### Discrepancy
```
= Counted End Saldo - Expected End Saldo
```

### Net Balance Per Currency
```
= Beginning Saldo[currency]
  + SUM(IN for currency)
  + SUM(IN_OUT for currency)
  - SUM(OUT for currency)
```

---

## Common Tasks

### Get Today's Summary
```php
$service = app(TelegramReportService::class);
$data = $service->getTodaySummary($manager);
```

### Get Shift Performance for Date
```php
$date = Carbon::parse('2024-01-15');
$data = $service->getShiftPerformance($manager, $date);
```

### Get Detailed Shift Info
```php
$data = $service->getShiftDetail($shiftId, $manager);
```

### Get Transactions for Period
```php
$start = Carbon::parse('2024-01-01');
$end = Carbon::parse('2024-01-31');
$data = $service->getTransactionActivity($manager, $start, $end);
```

### Get Multi-Location Comparison
```php
$data = $service->getMultiLocationSummary($manager);
```

---

## Model Methods (CashierShift)

### Status Checks
- `isOpen()` - Boolean
- `isClosed()` - Boolean
- `hasDiscrepancy()` - Boolean

### Calculations
- `calculateExpectedEndSaldo()` - Returns decimal
- `calculateDiscrepancy()` - Returns decimal or null
- `getDurationInHoursAttribute()` - Returns float or null

### Cash Flow
- `getTotalCashInAttribute()` - Returns sum
- `getTotalCashOutAttribute()` - Returns sum
- `getTotalCashInForCurrency(Currency)` - Returns sum
- `getTotalCashOutForCurrency(Currency)` - Returns sum
- `getNetBalanceForCurrency(Currency)` - Returns decimal

### Multi-Currency
- `getUsedCurrencies()` - Returns collection
- `getTransactionsByCurrency()` - Returns grouped collection
- `getBeginningSaldoForCurrency(Currency)` - Returns decimal
- `setBeginningSaldoForCurrency(Currency, amount)` - Void
- `getBeginningSaldosWithCurrency()` - Returns collection

### Scopes
- `->open()` - Filter open shifts
- `->closed()` - Filter closed shifts
- `->forUser($userId)` - Filter by user
- `->forDrawer($drawerId)` - Filter by drawer

### Static Methods
- `userHasOpenShift($userId)` - Boolean
- `getUserOpenShift($userId)` - Returns shift or null

---

## Relationships

### CashierShift Has:
- One CashDrawer
- One User (cashier)
- One User (approver, via approved_by)
- One User (rejecter, via rejected_by)
- Many CashTransactions
- Many BeginningSaldos
- Many EndSaldos
- One CashCount

### CashTransaction Belongs To:
- One CashierShift

### CashDrawer Belongs To:
- One Location

### User Can Have:
- Many CashierShifts (as cashier)
- Many Locations (manager access)

---

## Partially Implemented Features

1. **Discrepancy Auto-Escalation**
   - Fields exist but no automatic status change to UNDER_REVIEW
   - Could flag shifts with large discrepancies

2. **Category Analysis**
   - Categories stored but limited aggregation in reports
   - Could expand to detailed breakdowns

3. **Approval Workflow**
   - Basic fields present
   - No workflow state machine
   - Approval/rejection tracked but not enforced

---

## Performance Tips

1. **Use Eager Loading**
   ```php
   CashierShift::with('user', 'cashDrawer', 'transactions')->get()
   ```

2. **Select Only Needed Columns**
   ```php
   CashierShift::select('id', 'status', 'user_id')->get()
   ```

3. **Use Database Aggregations**
   ```php
   CashierShift::sum('expected_end_saldo')
   ```

4. **Paginate Large Results**
   ```php
   CashierShift::paginate(20)
   ```

---

## Next Steps for Enhancement

### Recommended New Reports
1. **Discrepancy Analysis** - Rates by cashier, trends, alerts
2. **Performance Rankings** - Efficiency scores, accuracy metrics
3. **Financial Summary** - Daily/weekly/monthly cash flow
4. **Audit Reports** - Approval/rejection rates, outliers
5. **Operational** - Peak hours, shift patterns, utilization

### Recommended Features
1. Automatic discrepancy alerts for large amounts
2. Historical trend analysis
3. Export to Excel/PDF
4. Scheduled daily summaries
5. Advanced filtering/search

---

## File Locations (in /tmp for this analysis)
- `/tmp/CashierShift.php` - Model definition
- `/tmp/TelegramReportService.php` - Report generation
- `/tmp/TelegramReportFormatter.php` - Report formatting
- `/tmp/TelegramPosController.php` - Webhook handler
- `/tmp/POS_SYSTEM_ANALYSIS.md` - Detailed analysis
- `/tmp/DATABASE_SCHEMA_VISUAL.txt` - Schema diagrams
- `/tmp/QUERY_EXAMPLES_AND_DATA_ACCESS.md` - Query reference
- `/tmp/QUICK_REFERENCE_GUIDE.md` - This file

