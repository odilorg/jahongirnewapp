# POS Cashier Shift Reporting System - Comprehensive Analysis

## Executive Summary

This is a Laravel-based Point of Sale (POS) system with a Telegram bot interface for cashier operations and management reporting. The system tracks cash transactions across shifts, locations, and currencies, with multi-language support (English, Russian, Uzbek).

---

## 1. DATABASE SCHEMA & MODELS

### 1.1 Core Models and Relationships

#### **CashierShift Model** (`/tmp/CashierShift.php`)
The central model representing a cashier's working period.

**Fields:**
- `id` (primary key)
- `cash_drawer_id` (FK) - Links to cash drawer location
- `user_id` (FK) - Links to cashier/user
- `status` (enum: ShiftStatus) - Current shift state
- `beginning_saldo` (decimal:2) - Starting cash balance (legacy, UZS only)
- `expected_end_saldo` (decimal:2) - Calculated closing balance
- `counted_end_saldo` (decimal:2) - Actual counted cash at close
- `discrepancy` (decimal:2) - Difference between expected and counted
- `discrepancy_reason` (text) - Explanation for discrepancies
- `opened_at` (datetime) - Shift start timestamp
- `closed_at` (datetime, nullable) - Shift end timestamp
- `notes` (text, nullable) - Additional notes
- `approved_at` (datetime, nullable) - Approval timestamp
- `rejected_at` (datetime, nullable) - Rejection timestamp
- `rejection_reason` (text, nullable) - Why shift was rejected
- `created_at`, `updated_at` (timestamps)

**Key Relationships:**
```
CashierShift
├── belongsTo(CashDrawer) - Physical cash drawer
├── belongsTo(User) - Cashier/operator
├── hasMany(CashTransaction) - All transactions in shift
├── hasOne(CashCount) - Cash count record
├── hasMany(BeginningSaldo) - Multi-currency opening balances
├── hasMany(EndSaldo) - Multi-currency closing balances
└── belongsTo(User, 'approved_by') - Approver (inferred from fields)
    belongsTo(User, 'rejected_by') - Rejecter (inferred from fields)
```

**Scopes (Query Builders):**
- `scopeOpen()` - Filters for OPEN status shifts
- `scopeClosed()` - Filters for CLOSED status shifts
- `scopeForUser($userId)` - Shifts by specific cashier
- `scopeForDrawer($drawerId)` - Shifts at specific drawer

**Key Methods:**
- `isOpen()`, `isClosed()` - Status checks
- `calculateExpectedEndSaldo()` - Compute expected closing balance (UZS)
- `calculateDiscrepancy()` - Compute difference between actual and expected
- `hasDiscrepancy()` - Boolean check if discrepancy exists
- `getDurationInHoursAttribute()` - Shift length in hours
- `getTotalCashInAttribute()` - Sum of IN/IN_OUT transactions
- `getTotalCashOutAttribute()` - Sum of OUT transactions
- `getTransactionsByCurrency()` - Group transactions by currency
- `getTotalCashInForCurrency(Currency)` - Currency-specific cash in
- `getTotalCashOutForCurrency(Currency)` - Currency-specific cash out
- `getNetBalanceForCurrency(Currency)` - Net balance calculation: beginning_saldo + cash_in - cash_out
- `getBeginningSaldoForCurrency(Currency)` - Get opening balance for currency
- `getUsedCurrencies()` - List all currencies in shift
- `userHasOpenShift(userId)` - Static check for open shift
- `getUserOpenShift(userId)` - Retrieve user's active shift

#### **CashTransaction Model** (Referenced, not shown)
Individual cash movements within a shift.

**Inferred Fields:**
- `id` (primary key)
- `cashier_shift_id` (FK) - Links to shift
- `type` (enum: TransactionType) - IN, OUT, or IN_OUT (exchange)
- `amount` (decimal) - Transaction amount
- `currency` (enum: Currency) - Currency code
- `category` (string) - Transaction category
- `notes` (text, nullable) - Description/notes
- `occurred_at` (datetime) - When transaction occurred
- `effective_amount` (decimal, computed) - Amount with sign applied
- `created_at`, `updated_at` (timestamps)

**Relationships:**
- `belongsTo(CashierShift)` - Parent shift

#### **CashDrawer Model** (Referenced, not shown)
Physical cash drawer locations.

**Inferred Fields:**
- `id`
- `name` (string) - Drawer identifier
- `location_id` (FK) - Links to location

**Relationships:**
- `belongsTo(Location)` - Parent location
- `hasMany(CashierShift)` - All shifts at this drawer

#### **BeginningSaldo Model** (Referenced, not shown)
Multi-currency opening balances for shifts.

**Fields:**
- `cashier_shift_id` (FK)
- `currency` (string) - Currency code
- `amount` (decimal:2) - Opening balance
- `formatted_amount` (string, computed)

#### **EndSaldo Model** (Referenced, not shown)
Multi-currency closing balances and discrepancies.

**Fields:**
- `cashier_shift_id` (FK)
- `currency` (string) - Currency code
- `expected_end_saldo` (decimal:2)
- `counted_end_saldo` (decimal:2)
- `discrepancy` (decimal:2)

#### **User Model** (Referenced)
System users (cashiers, managers, admins).

**Key Attributes (inferred):**
- `id`
- `name`
- `phone_number`
- `telegram_pos_user_id` - Telegram user ID for bot linking
- `roles` (relation) - User permissions

**Relationships:**
- `hasMany(CashierShift)` - As cashier
- `hasMany(CashierShift, 'approved_by')` - Approved shifts
- `hasMany(CashierShift, 'rejected_by')` - Rejected shifts
- `hasMany(Location)` - Accessible locations (for managers)

#### **Location Model** (Referenced)
Physical business locations/branches.

**Fields (inferred):**
- `id`
- `name` - Location name

**Relationships:**
- `hasMany(CashDrawer)` - Drawers at location
- `belongsToMany(User)` - Managers with access

#### **TelegramPosSession Model** (Referenced)
Telegram bot session tracking.

**Fields:**
```sql
CREATE TABLE telegram_pos_sessions (
    id BIGINT PRIMARY KEY,
    chat_id BIGINT UNIQUE,
    user_id BIGINT NULLABLE FOREIGN KEY -> users.id,
    language VARCHAR(10) DEFAULT 'en',
    state VARCHAR(50) DEFAULT 'main_menu',
    data JSON NULLABLE,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    INDEX chat_id,
    INDEX updated_at
);
```

---

### 1.2 Enums

#### **ShiftStatus**
```php
enum ShiftStatus {
    case OPEN;           // Shift currently active
    case CLOSED;         // Shift ended, waiting review
    case UNDER_REVIEW;   // Shift flagged for manager review
}
```

#### **TransactionType**
```php
enum TransactionType {
    case IN;       // Cash inflow (sales, deposits)
    case OUT;      // Cash outflow (payouts, expenses)
    case IN_OUT;   // Exchange (currency swap, multi-currency)
}
```

#### **Currency**
```php
enum Currency {
    case UZS;   // Uzbek Som (primary currency)
    case USD;   // US Dollar (inferred from code)
    case EUR;   // Euro (inferred)
    // Additional currencies possible via cases() method
}
```

---

## 2. CURRENT REPORTING CAPABILITIES

### 2.1 Report Service: `TelegramReportService` 

#### **Method 1: `getTodaySummary(User $manager): array`**
**Purpose:** High-level overview of all operations for current day

**Output Structure:**
```php
[
    'date' => Carbon date,
    'location' => string (location names),
    'shifts' => [
        'total' => int,
        'open' => int,
        'closed' => int,
        'under_review' => int,
    ],
    'transactions' => [
        'total' => int,
        'cash_in' => int (count),
        'cash_out' => int (count),
        'exchange' => int (count),
    ],
    'currency_totals' => [
        'UZS' => ['cash_in' => float, 'cash_out' => float, 'net' => float],
        'USD' => [...],
        // ... per currency
    ],
    'active_cashiers' => int,
    'discrepancies' => int,
    'top_performer' => [
        'name' => string,
        'transaction_count' => int,
        'shift_id' => int,
    ],
]
```

**Data Aggregation:**
- Counts shifts by status
- Counts transactions by type
- Calculates totals per currency
- Identifies most active cashier by transaction volume

**Metrics Tracked:**
- Shift status distribution
- Transaction type breakdown
- Net cash flow by currency
- Active cashier count
- Top performer identification

---

#### **Method 2: `getShiftPerformance(User $manager, Carbon $date): array`**
**Purpose:** Detailed view of individual shift performance for a specific date

**Output Structure:**
```php
[
    'date' => Carbon,
    'shifts' => Collection of [
        'shift_id' => int,
        'cashier_name' => string,
        'drawer_name' => string,
        'opened_at' => datetime,
        'closed_at' => datetime,
        'duration_minutes' => int,
        'status' => ShiftStatus,
        'transaction_count' => int,
        'currency_balances' => [
            'UZS' => float,
            'USD' => float,
            // ...
        ],
        'has_discrepancy' => bool,
        'discrepancy_info' => null (not implemented),
    ],
    'total_shifts' => int,
    'total_transactions' => int,
    'avg_duration' => float (minutes),
]
```

**Metrics Tracked:**
- Per-shift duration
- Per-shift transaction count
- Multi-currency balances per shift
- Shift status
- Average shift duration across day

---

#### **Method 3: `getShiftDetail(int $shiftId, User $manager): array`**
**Purpose:** Deep dive into single shift with transaction details

**Output Structure:**
```php
[
    'shift' => [
        'id' => int,
        'cashier' => string,
        'drawer' => string,
        'location' => string,
        'opened_at' => datetime,
        'closed_at' => datetime,
        'duration' => int (minutes),
        'status' => ShiftStatus,
    ],
    'transactions' => [
        'total' => int,
        'by_type' => [
            'cash_in' => int,
            'cash_out' => int,
            'exchange' => int,
        ],
        'recent' => Collection of [
            'id' => int,
            'type' => TransactionType,
            'amount' => float,
            'currency' => string,
            'category' => string,
            'notes' => string,
            'occurred_at' => datetime,
        ],
    ],
    'balances' => [
        'UZS' => float,
        'USD' => float,
        // ... per currency
    ],
    'discrepancy' => null, // Not implemented
    'approval' => [
        'approved_by' => string (user name),
        'approved_at' => datetime,
        'rejected_by' => string,
        'rejected_at' => datetime,
        'rejection_reason' => string,
    ],
]
```

**Features:**
- Last 20 transactions listed
- Full currency breakdown
- Approval/rejection status
- Multi-currency balance summary

---

#### **Method 4: `getTransactionActivity(User $manager, Carbon $startDate, Carbon $endDate): array`**
**Purpose:** Transaction-level analysis across a date range

**Output Structure:**
```php
[
    'period' => [
        'start' => Carbon,
        'end' => Carbon,
    ],
    'summary' => [
        'total_transactions' => int,
        'total_amount' => float,
    ],
    'by_category' => [
        'category_name' => [
            'count' => int,
            'total_amount' => float,
        ],
        // ... per category
    ],
    'by_currency' => [
        'UZS' => [
            'count' => int,
            'cash_in' => float,
            'cash_out' => float,
            'net' => float,
        ],
        // ... per currency
    ],
    'top_cashiers' => [
        'Cashier Name' => int (transaction count),
        // ... top 5
    ],
    'by_type' => [
        'cash_in' => int (count),
        'cash_out' => int (count),
        'exchange' => int (count),
    ],
]
```

**Metrics Tracked:**
- Transaction volume per category
- Currency-wise cash in/out/net
- Top cashiers by transaction volume
- Transaction type distribution

---

#### **Method 5: `getMultiLocationSummary(User $manager): array`**
**Purpose:** Compare performance across multiple locations (for managers/admins)

**Output Structure:**
```php
[
    'date' => Carbon,
    'locations' => [
        [
            'location_name' => string,
            'location_id' => int,
            'shifts' => [
                'total' => int,
                'open' => int,
                'closed' => int,
            ],
            'transactions' => [
                'total' => int,
                'total_amount' => float,
            ],
            'active_cashiers' => int,
        ],
        // ... per location
    ],
    'total_locations' => int,
]
```

**Metrics Tracked:**
- Shifts per location
- Transactions per location
- Active cashiers per location
- Total transaction volume per location

---

### 2.2 Report Formatter: `TelegramReportFormatter`

Converts report data to human-readable Telegram messages with:
- Emojis for visual clarity
- Multi-language support (via `__()` helper)
- Formatted currency display
- Duration formatting (hours/minutes)
- Message limits (max 10 shifts per message to respect Telegram limits)

**Supported Languages:**
- English (en)
- Russian (ru)
- Uzbek (uz)

---

### 2.3 Time Periods Supported

From TelegramPosController:
1. **Today** - Current date summary (getTodaySummary, getShiftPerformance for today)
2. **Custom Date Range** - Transaction activity can query any date range
3. **Multi-location** - Today's data across all locations

**Limitations:**
- Most reports default to TODAY
- Transaction activity supports date range but controller only uses today
- No built-in monthly/weekly/quarterly reports yet

---

## 3. BUSINESS LOGIC

### 3.1 What is a "Shift"?

A shift represents a cashier's work session:
1. **Opening Phase** - Cashier starts shift with beginning_saldo (opening balance)
2. **Active Phase** - Transactions recorded throughout day
3. **Closing Phase** - Cashier counts physical cash (counted_end_saldo)
4. **Verification Phase** - System compares expected vs. actual (discrepancy check)
5. **Approval Phase** - Manager reviews and approves/rejects

**Workflow:**
```
START SHIFT
    ├─ beginning_saldo set
    └─ status = OPEN
    
RECORD TRANSACTIONS
    ├─ IN transactions
    ├─ OUT transactions
    └─ IN_OUT (currency exchanges)
    
CLOSE SHIFT
    ├─ counted_end_saldo provided
    └─ status = CLOSED
    
CALCULATE
    ├─ expected_end_saldo = beginning_saldo + cash_in - cash_out
    ├─ discrepancy = counted_end_saldo - expected_end_saldo
    └─ status = UNDER_REVIEW (if discrepancy != 0)
    
APPROVAL
    ├─ Manager reviews shift
    ├─ If OK: approved_at set, status unchanged
    └─ If rejected: rejected_at, rejection_reason set
```

---

### 3.2 Transaction Types

#### **IN** - Cash Inflow
- Sales transactions
- Deposits from customers
- Initial cash
- Currency deposits

#### **OUT** - Cash Outflow
- Payouts to staff
- Expenses
- Cash withdrawals
- Refunds

#### **IN_OUT** - Exchange
- Currency exchanges (e.g., UZS ↔ USD)
- Complex multi-leg transactions
- Counted as both IN and OUT for calculations

**Calculation Logic:**
```php
Total Cash In = IN transactions + IN_OUT transactions
Total Cash Out = OUT transactions (only)
Net = beginning_saldo + Total Cash In - Total Cash Out
```

---

### 3.3 Currencies

**Primary Currency:** UZS (Uzbek Som)
**Secondary Currencies:** USD, EUR, and potentially others

**Multi-Currency Support:**
- Each transaction tagged with specific currency
- Separate tracking per currency via BeginningSaldo/EndSaldo tables
- Net balance calculated per currency
- No automatic currency conversion (manual exchanges via IN_OUT type)

**Multi-Currency Calculation:**
```php
For each Currency:
    beginning_saldo = BeginningSaldo table lookup or legacy field for UZS
    net_balance = beginning_saldo + cash_in_for_currency - cash_out_for_currency
```

---

### 3.4 Transaction Categories

**Supported Categories:** (referenced but values not defined in provided code)
- Likely includes: Sales, Expenses, Payouts, Deposits, Exchanges, etc.
- Each transaction can be tagged with a category for aggregation

---

### 3.5 Approval/Verification Workflows

**Current Implementation Status:** Partially implemented

**Fields Present:**
- `approved_at` - When shift was approved
- `rejected_at` - When shift was rejected
- `rejection_reason` - Why rejected
- `approver_id` (inferred) - Who approved
- `rejecter_id` (inferred) - Who rejected

**Workflow Features:**
- Managers can view approval status in shift details
- Discrepancy tracking (if discrepancy != 0, shift may go to UNDER_REVIEW)
- Rejection reasons documented

**Not Yet Implemented:**
- Discrepancy resolution in reports (shows "Feature not implemented")
- Workflow state machine enforcement
- Automatic escalation for large discrepancies

---

## 4. DATA POINTS AVAILABLE FOR REPORTING

### 4.1 Financial Metrics

#### Shift-Level
- **Beginning Balance** - Starting cash per currency
- **Total Cash In** - Sum of IN + IN_OUT transactions
- **Total Cash Out** - Sum of OUT transactions
- **Expected Ending Balance** - Calculated balance
- **Counted Ending Balance** - Actual cash counted
- **Discrepancy** - Difference (counted - expected)
- **Net Balance per Currency** - Beginning + In - Out

#### Transaction-Level
- **Amount** - Transaction value
- **Currency** - Transaction currency
- **Type** - IN, OUT, IN_OUT
- **Category** - Transaction classification
- **Effective Amount** - Signed amount based on type

#### Aggregated
- **Total Daily Cash In/Out** - Across all shifts
- **Total Transactions** - Count by type
- **Currency Totals** - Net per currency
- **Location Totals** - Aggregated by branch

---

### 4.2 Time-Based Data

#### Shift Duration
- **Opened At** - Shift start timestamp
- **Closed At** - Shift end timestamp
- **Duration in Hours** - Calculated difference
- **Duration in Minutes** - For individual transaction tracking

#### Transaction Timing
- **Occurred At** - Exact transaction timestamp
- **Time of Day Patterns** - Analyzable from occurred_at

#### Audit Trail
- **Approved At** - Approval timestamp
- **Rejected At** - Rejection timestamp

---

### 4.3 User/Cashier Performance

- **Active Cashiers Count** - Users with open shifts
- **Top Performer** - By transaction volume
- **Transaction Volume per Cashier** - Top 5 cashiers
- **Shift Duration per Cashier** - Work pattern analysis
- **Discrepancy Frequency per Cashier** - Accuracy metrics

---

### 4.4 Location/Drawer Data

- **Location Name** - Business branch
- **Drawer Name** - Specific cash drawer
- **Shifts per Location** - Activity level
- **Transactions per Location** - Volume
- **Active Cashiers per Location** - Staffing level
- **Multi-Location Comparison** - Performance ranking

---

### 4.5 Discrepancies and Approvals

#### Discrepancy Information
- **Discrepancy Amount** - Counted - Expected
- **Discrepancy Reason** - Notes from cashier
- **Has Discrepancy Flag** - Boolean check
- **Discrepancy per Currency** - Multi-currency discrepancies
- **Discrepancy Frequency** - How often it occurs

#### Approval Workflow
- **Approval Status** - Approved, Rejected, Pending
- **Approver Name** - Manager who approved
- **Rejection Reason** - If rejected
- **Rejection Frequency** - Rate of rejections

---

## 5. UNREALIZED/PARTIALLY IMPLEMENTED FEATURES

### Currently Marked as "Not Implemented"
1. **Discrepancy Feature** - Referenced but not fully active
   - Fields exist (`discrepancy`, `discrepancy_reason`)
   - Calculation methods present
   - But reporting doesn't leverage them fully

2. **Status Under Review** - Enum value exists but not automatically triggered
   - Could flag shifts with significant discrepancies
   - No auto-escalation rules

3. **Category-Level Analysis** - Categories stored but limited aggregation
   - Could expand to detailed category breakdowns

---

## 6. AUTHORIZATION & ACCESS CONTROL

**Role-Based Access:**
```php
- Managers: Can view reports for their assigned locations
- Super Admin: Can view all reports, all locations
- Cashiers: No report access (main menu only)
```

**Scoping:**
- Managers can only see shifts from their locations
- Drawer access filtered by location
- Super admins see everything

---

## 7. INTEGRATION POINTS

### Telegram Bot
- Webhook-based integration
- Session management with `telegram_pos_sessions` table
- Multi-language support
- Inline keyboard callbacks for report selection

### Related Actions (Not Shown)
- `StartShiftAction` - Initiates shift
- `CloseShiftAction` - Ends shift and triggers discrepancy calc
- `RecordTransactionAction` - Logs individual transactions

---

## 8. SUMMARY TABLE

| Aspect | Details |
|--------|---------|
| **Primary Key Model** | CashierShift |
| **Transaction Model** | CashTransaction |
| **Reporting Service** | TelegramReportService (5 methods) |
| **Formatting Service** | TelegramReportFormatter |
| **Statuses** | OPEN, CLOSED, UNDER_REVIEW |
| **Transaction Types** | IN, OUT, IN_OUT (Exchange) |
| **Currencies** | UZS (primary), USD, EUR, others |
| **Time Periods** | Today, Custom date range |
| **Authorization** | Manager, Super Admin roles |
| **Interface** | Telegram Bot via webhook |
| **Languages** | EN, RU, UZ |
| **Locations** | Multi-location support |

---

## 9. RECOMMENDATIONS FOR NEW REPORTS

Based on available data, consider:

1. **Discrepancy Analysis Report**
   - Discrepancy rates by cashier
   - Discrepancy trends over time
   - Large discrepancy alerts

2. **Performance Rankings**
   - Cashier efficiency scores
   - Transaction speed metrics
   - Accuracy rankings

3. **Financial Summary Reports**
   - Daily/weekly/monthly cash flow
   - Net balance by currency over time
   - Currency exchange volume

4. **Audit Reports**
   - Approval/rejection rates
   - Outlier shifts (unusually high/low activity)
   - Category-wise breakdown

5. **Operational Reports**
   - Peak hours analysis
   - Shift duration trends
   - Staff utilization by location

