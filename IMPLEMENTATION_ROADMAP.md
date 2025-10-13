# 🗺️ POS System Implementation Roadmap

## Project Status: Phase 1 Complete (65% Overall)

Branch: `feature/hotel-pos-compliance`

---

## ✅ Phase 1: Core Architecture (COMPLETED)

### Database & Models ✅
- [x] Location entity (Hotel → Location → CashDrawer hierarchy)
- [x] location_id foreign key on cash_drawers
- [x] balances JSON column on cash_drawers
- [x] under_review status in ShiftStatus enum
- [x] related_currency & related_amount on cash_transactions
- [x] location_user pivot table for cashier assignments
- [x] User-Location many-to-many relationship
- [x] Location-User relationship methods

### Files Modified (Committed) ✅
- `app/Models/Location.php` - NEW
- `app/Models/CashDrawer.php` - Enhanced with location & balance methods
- `app/Models/CashTransaction.php` - Exchange transaction support
- `app/Models/Hotel.php` - locations() relationship
- `app/Models/User.php` - locations() relationship
- `app/Enums/ShiftStatus.php` - Added UNDER_REVIEW
- `database/migrations/2025_10_13_184303_create_locations_table.php`
- `database/migrations/2025_10_13_184450_add_location_id_and_balances_to_cash_drawers_table.php`
- `database/migrations/2025_10_13_184838_add_exchange_fields_to_cash_transactions_table.php`
- `database/migrations/2025_10_13_185652_create_location_user_table.php`

---

## 🔨 Phase 2: Business Logic & Workflows (IN PROGRESS)

### Priority 1: Core Shift Workflow 🎯

#### 2.1 Shift Opening Logic ⏳
**Files to Create/Modify:**
- [ ] `app/Actions/StartShiftAction.php`
  - Auto-preselect location based on user's assigned locations
  - If only 1 location → auto-select
  - If multiple → show dropdown with assigned locations only
  - Carry over ending balances from previous shift as beginning balances
  - Initialize shift with current timestamp

**Implementation Steps:**
```php
// In StartShiftAction:
1. Get user's assigned locations: $user->locations()
2. Find last closed shift for selected drawer
3. Copy endSaldos as beginningSaldos for new shift
4. Set status = 'open', opened_at = now()
5. Create shift record
```

#### 2.2 Running Balance Calculation ⏳
**Files to Modify:**
- [ ] `app/Models/CashierShift.php`
  - Add `getRunningBalanceForCurrency(Currency $currency)` method
  - Real-time calculation: beginning + cash_in - cash_out
  - Cache calculation for performance

**Implementation:**
```php
public function getRunningBalanceForCurrency(Currency $currency): float
{
    $beginning = $this->getBeginningSaldoForCurrency($currency);
    $cashIn = $this->getTotalCashInForCurrency($currency);
    $cashOut = $this->getTotalCashOutForCurrency($currency);

    return $beginning + $cashIn - $cashOut;
}
```

#### 2.3 Shift Closing & Reconciliation ⏳
**Files to Create/Modify:**
- [ ] `app/Actions/CloseShiftAction.php` (may already exist - needs enhancement)
  - Enter counted totals per currency
  - Calculate expected vs counted for each currency
  - If discrepancy detected → status = 'under_review'
  - If match → status = 'closed'
  - Require discrepancy_reason if mismatch
  - Update drawer balances JSON with counted amounts
  - Set closed_at timestamp

**Logic:**
```php
foreach ($currencies as $currency) {
    $expected = $shift->getNetBalanceForCurrency($currency);
    $counted = $countedAmounts[$currency];
    $discrepancy = $counted - $expected;

    if (abs($discrepancy) > 0.01) {
        $shift->status = ShiftStatus::UNDER_REVIEW;
        // Create EndSaldo record with discrepancy
    }
}

// Update drawer balances
$shift->cashDrawer->initializeBalancesFromShift($shift);
```

#### 2.4 Transaction Auto-Timestamping ⏳
**Files to Modify:**
- [ ] `app/Models/CashTransaction.php`
  - Add `boot()` method
  - Auto-set `occurred_at = now()` on creating

**Implementation:**
```php
protected static function boot()
{
    parent::boot();

    static::creating(function ($transaction) {
        if (!$transaction->occurred_at) {
            $transaction->occurred_at = now();
        }

        if (!$transaction->created_by) {
            $transaction->created_by = auth()->id();
        }
    });
}
```

---

### Priority 2: Role-Based Access Control 🔒

#### 2.5 Permissions & Policies ⏳
**Files to Create/Modify:**
- [ ] `app/Policies/CashierShiftPolicy.php` (enhance existing)
  - `update()`: Allow only if shift is open AND user owns it
  - Managers/admins can always update
  - After close, only managers/admins can edit

- [ ] `app/Policies/CashTransactionPolicy.php` (enhance existing)
  - `create()`: Only if shift is open
  - `update()/delete()`: Only if shift is open AND user created it
  - Managers can override

**Implementation:**
```php
// CashierShiftPolicy
public function update(User $user, CashierShift $shift): bool
{
    // Managers and admins can always edit
    if ($user->hasAnyRole(['manager', 'admin', 'super_admin'])) {
        return true;
    }

    // Cashiers can only edit their own open shifts
    return $shift->user_id === $user->id && $shift->isOpen();
}
```

---

### Priority 3: Manager Approval Workflow 👨‍💼

#### 2.6 Approval Actions ⏳
**Files to Create:**
- [ ] `app/Actions/ApproveShiftAction.php`
  - Manager reviews under_review shift
  - Can approve (status → closed) or reject (stays under_review)
  - Add approval notes
  - Notification to cashier

**UI Location:** CashierShiftResource table action

---

## 🎨 Phase 3: Filament UI & Resources (PENDING)

### 3.1 LocationResource 📍
**File to Create:**
- [ ] `app/Filament/Resources/LocationResource.php`
- [ ] `app/Filament/Resources/LocationResource/Pages/*.php`

**Features:**
- CRUD for locations
- Hotel selection
- Status toggle (active/inactive)
- Assign cashiers (multi-select User)
- Show assigned cash drawers
- Show shifts count

**Form Schema:**
```php
Forms\Components\Select::make('hotel_id')
    ->relationship('hotel', 'name')
    ->required(),
Forms\Components\TextInput::make('name')->required(),
Forms\Components\Toggle::make('is_active')->default(true),
Forms\Components\Textarea::make('description'),
Forms\Components\Select::make('users')
    ->relationship('users', 'name')
    ->multiple()
    ->preload()
    ->searchable(),
```

### 3.2 Update CashDrawerResource 💰
**File to Modify:**
- [ ] `app/Filament/Resources/CashDrawerResource.php`

**Changes:**
- Add location_id selection
- Display location.name in table
- Show current balances per currency (from JSON)
- Filter by location
- Filter by hotel (through location)

### 3.3 Enhanced CashierShiftResource 🕐
**Files to Modify:**
- [ ] `app/Filament/Resources/CashierShiftResource.php`
- [ ] `app/Filament/Resources/CashierShiftResource/Pages/StartShift.php`
- [ ] `app/Filament/Resources/CashierShiftResource/Pages/CloseShift.php`

**StartShift Page:**
- Auto-preselect location for cashier
- Show drawer selection (filtered by location)
- Display previous shift ending balances
- Confirm beginning balances (pre-filled, editable by managers)

**CloseShift Page:**
- Show expected balances per currency
- Input counted balances per currency
- Auto-calculate discrepancies
- Show discrepancy alert if mismatch
- Require discrepancy_reason field if mismatch
- Denomination breakdown (optional)

**Table Enhancements:**
- Add "Approve" action for under_review shifts (managers only)
- Color-code status badges (open=green, closed=gray, under_review=yellow)
- Show running balances in ViewCashierShift page

### 3.4 Enhanced CashTransactionResource 💵
**File to Modify:**
- [ ] `app/Filament/Resources/CashTransactionResource.php`

**Changes:**
- Display exchange details if isExchange()
- Show related_currency and related_amount for IN_OUT types
- Auto-timestamp (no manual entry)
- Location context from shift

---

## 📊 Phase 4: Reporting & Dashboards (FUTURE)

### 4.1 Real-Time Balance Widget 📈
**File to Create:**
- [ ] `app/Filament/Widgets/RunningBalanceWidget.php`

**Features:**
- Show current shift running balances per currency
- Auto-refresh every 30 seconds (Livewire polling)
- Color-coded: green if positive, red if negative
- Only for cashiers with open shifts

### 4.2 Enhanced Reports Page 📊
**File to Modify:**
- [ ] `app/Filament/Pages/Reports.php`

**New Reports:**
- Discrepancy Report (all under_review shifts)
- Location Performance (totals by location)
- Cashier Performance (shifts per cashier, avg discrepancy)
- Multi-Currency Summary

---

## 🌱 Phase 5: Seeders & Test Data (PENDING)

### 5.1 Location Seeder 🏨
**File to Create:**
- [ ] `database/seeders/LocationSeeder.php`

**Sample Data:**
```php
Hotel::first()->locations()->createMany([
    ['name' => 'Restaurant', 'status' => 'active'],
    ['name' => 'Bar', 'status' => 'active'],
    ['name' => 'Front Desk', 'status' => 'active'],
    ['name' => 'Pool Bar', 'status' => 'inactive'],
]);
```

### 5.2 User-Location Assignment Seeder
- Assign cashiers to locations
- John → Restaurant
- Mary → Bar, Front Desk (multiple)

---

## 🧪 Phase 6: Testing (FUTURE)

### 6.1 Feature Tests
- [ ] Shift opening with balance carry-over
- [ ] Transaction creation with auto-timestamp
- [ ] Shift closing with discrepancy detection
- [ ] Manager approval workflow
- [ ] Permission checks (cashier vs manager)

### 6.2 Integration Tests
- [ ] Multi-currency balance calculations
- [ ] Exchange transaction processing
- [ ] Location-based filtering

---

## 📋 Technical Debt & Known Issues

1. **User model has stub role methods** (lines 126-139 in User.php)
   - Needs Spatie Permission properly configured
   - Current stubs return `true` for all roles (INSECURE!)

2. **Transaction category needs enum**
   - Currently using TransactionCategory enum
   - Should align with conversation: payment, expense, exchange

3. **Auto-timestamps not yet implemented**
   - Need `boot()` method in CashTransaction

4. **No validation for multi-currency transactions**
   - Need to ensure related_amount exists if related_currency is set

---

## 🚀 Quick Start for Next Session

### To Continue Implementation:

```bash
# 1. Check current branch
git status

# 2. Start with highest priority
# Implement StartShiftAction with auto-preselect logic

# 3. Run tests
php artisan test

# 4. Create LocationResource
php artisan make:filament-resource Location

# 5. Update CashierShiftResource forms
```

### Current Compliance Status:

**Overall: 65% Complete**

| Feature | Status | Priority |
|---------|--------|----------|
| Hotel-Location-Drawer hierarchy | ✅ 100% | ✅ Done |
| Multi-currency support | ✅ 100% | ✅ Done |
| Exchange transactions | ✅ 95% | ⚠️ Needs UI |
| Under-review status | ✅ 100% | ✅ Done |
| User-Location assignment | ✅ 100% | ✅ Done |
| Shift workflow | ⚠️ 30% | 🔥 HIGH |
| Running balances | ⚠️ 40% | 🔥 HIGH |
| Role permissions | ⚠️ 20% | 🔥 HIGH |
| Manager approval | ❌ 0% | 🔥 HIGH |
| Filament UI | ❌ 0% | 🟡 MED |
| Reporting | ✅ 85% | 🟢 LOW |
| Testing | ❌ 0% | 🟢 LOW |

---

## 📝 Notes

- All database migrations are completed and run successfully
- Models have proper relationships defined
- Enum values are correctly set up
- Next focus: Business logic implementation before UI
- Consider adding queued jobs for balance calculations if performance becomes an issue

---

**Last Updated:** 2025-10-13
**Author:** Claude Code Assistant
**Branch:** feature/hotel-pos-compliance
