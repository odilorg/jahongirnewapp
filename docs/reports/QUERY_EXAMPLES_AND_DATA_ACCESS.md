# POS System - Query Examples & Data Access Patterns

## 1. DATA ACCESS EXAMPLES

### Accessing Shift Data

```php
// Get a specific shift with all relationships
$shift = CashierShift::with([
    'user',
    'cashDrawer',
    'transactions',
    'beginningSaldos',
    'endSaldos'
])->find($shiftId);

// Get a cashier's shifts
$shifts = $cashier->cashierShifts()->get();

// Get open shifts for a user
$openShift = CashierShift::getUserOpenShift($userId);
if ($openShift) {
    echo "Cashier has open shift: {$openShift->id}";
}

// Find all shifts in a date range
$shifts = CashierShift::whereBetween('opened_at', [$startDate, $endDate])->get();

// Get shifts by status
$closedShifts = CashierShift::closed()->get();
$openShifts = CashierShift::open()->get();

// Get shifts by drawer
$drawerShifts = CashierShift::forDrawer($drawerId)->get();
```

### Accessing Transaction Data

```php
// Get all transactions in a shift
$transactions = $shift->transactions()->get();

// Get transactions by type
$cashInTransactions = $shift->transactions()
    ->whereIn('type', [TransactionType::IN, TransactionType::IN_OUT])
    ->get();

// Get cash out only
$cashOutTransactions = $shift->transactions()
    ->where('type', TransactionType::OUT)
    ->get();

// Get recent transactions (last 20)
$recent = $shift->transactions()
    ->orderBy('occurred_at', 'desc')
    ->limit(20)
    ->get();

// Get transactions in a time range
$transactions = CashTransaction::whereBetween('occurred_at', [$start, $end])
    ->get();

// Get transactions by category
$byCategory = $shift->transactions()
    ->groupBy('category')
    ->map(fn($group) => [
        'count' => $group->count(),
        'total' => $group->sum('amount'),
    ]);

// Get transactions by currency
$byType = $shift->transactions()->groupBy('type')->get();
$byCurrency = $shift->transactions()->groupBy('currency')->get();
```

### Accessing Multi-Currency Data

```php
// Get beginning saldo for a specific currency
$uzsSaldo = $shift->getBeginningSaldoForCurrency(Currency::UZS);

// Get all currencies used in shift
$currencies = $shift->getUsedCurrencies();

// Get transactions grouped by currency
$byCurrency = $shift->getTransactionsByCurrency();

// Get cash in/out for specific currency
$uzsIn = $shift->getTotalCashInForCurrency(Currency::UZS);
$uzsOut = $shift->getTotalCashOutForCurrency(Currency::UZS);

// Calculate net balance for currency
$netUZS = $shift->getNetBalanceForCurrency(Currency::UZS);

// Set beginning saldo for currency
$shift->setBeginningSaldoForCurrency(Currency::USD, 1000.00);
```

---

## 2. REPORTING QUERY PATTERNS

### Today's Summary Queries

```php
// Count shifts by status
$today = Carbon::today();
$shiftsQuery = CashierShift::whereDate('opened_at', $today);

$totalShifts = $shiftsQuery->count();
$openShifts = $shiftsQuery->where('status', ShiftStatus::OPEN)->count();
$closedShifts = $shiftsQuery->where('status', ShiftStatus::CLOSED)->count();
$underReview = $shiftsQuery->where('status', ShiftStatus::UNDER_REVIEW)->count();

// Get all transactions for today
$shiftIds = $shiftsQuery->pluck('id');
$transactions = CashTransaction::whereIn('cashier_shift_id', $shiftIds)
    ->whereDate('occurred_at', $today)
    ->get();

// Count by transaction type
$cashInCount = $transactions->where('type', TransactionType::IN)->count();
$cashOutCount = $transactions->where('type', TransactionType::OUT)->count();
$exchangeCount = $transactions->where('type', TransactionType::IN_OUT)->count();

// Calculate totals by currency
foreach (Currency::cases() as $currency) {
    $currencyTxns = $transactions->where('currency', $currency->value);
    $cashIn = $currencyTxns->where('type', TransactionType::IN)->sum('amount');
    $cashOut = $currencyTxns->where('type', TransactionType::OUT)->sum('amount');
    $net = $cashIn - $cashOut;
}

// Get active cashiers
$activeCashiers = $shiftsQuery
    ->where('status', ShiftStatus::OPEN)
    ->with('user')
    ->get()
    ->unique('user_id')
    ->count();
```

### Shift Performance Queries

```php
// Get all shifts for a date with details
$date = Carbon::today();
$shifts = CashierShift::whereDate('opened_at', $date)
    ->with(['user', 'cashDrawer', 'transactions'])
    ->get();

// Calculate metrics per shift
foreach ($shifts as $shift) {
    $duration = $shift->opened_at->diffInMinutes($shift->closed_at ?? now());
    $transactionCount = $shift->transactions->count();
    
    // Currency balances
    $balances = [];
    foreach (Currency::cases() as $currency) {
        $net = $shift->getNetBalanceForCurrency($currency);
        if ($net != 0) {
            $balances[$currency->value] = $net;
        }
    }
}

// Average shift duration
$avgDuration = $shifts->avg(fn($s) => 
    $s->opened_at->diffInMinutes($s->closed_at ?? now())
);
```

### Transaction Activity Queries

```php
// Get transactions in a date range
$startDate = Carbon::parse('2024-01-01');
$endDate = Carbon::parse('2024-01-31');

$transactions = CashTransaction::whereBetween('occurred_at', [$startDate, $endDate])
    ->with(['shift.user', 'shift.cashDrawer'])
    ->get();

// Group by category
$byCategory = $transactions
    ->groupBy('category')
    ->map(fn($group) => [
        'count' => $group->count(),
        'total_amount' => $group->sum('amount'),
    ]);

// Group by currency with details
$byCurrency = $transactions
    ->groupBy('currency')
    ->map(fn($group) => [
        'count' => $group->count(),
        'cash_in' => $group->where('type', TransactionType::IN)->sum('amount'),
        'cash_out' => $group->where('type', TransactionType::OUT)->sum('amount'),
        'net' => $group->sum('effective_amount'),
    ]);

// Top cashiers by transaction volume
$topCashiers = $transactions
    ->groupBy('shift.user.name')
    ->map(fn($group) => $group->count())
    ->sortDesc()
    ->take(5);
```

### Multi-Location Queries

```php
// Get all locations for manager
$locations = auth()->user()->hasRole('super_admin') 
    ? Location::all() 
    : auth()->user()->locations;

$today = Carbon::today();

foreach ($locations as $location) {
    // Get drawers for location
    $drawerIds = CashDrawer::where('location_id', $location->id)->pluck('id');
    
    // Get shifts for location
    $shifts = CashierShift::whereIn('cash_drawer_id', $drawerIds)
        ->whereDate('opened_at', $today)
        ->get();
    
    // Get transactions
    $transactions = CashTransaction::whereIn('cashier_shift_id', $shifts->pluck('id'))
        ->whereDate('occurred_at', $today)
        ->get();
    
    // Compile location stats
    $stats = [
        'location_name' => $location->name,
        'shifts_total' => $shifts->count(),
        'shifts_open' => $shifts->where('status', ShiftStatus::OPEN)->count(),
        'shifts_closed' => $shifts->where('status', ShiftStatus::CLOSED)->count(),
        'transactions_total' => $transactions->count(),
        'transactions_amount' => $transactions->sum('amount'),
        'active_cashiers' => $shifts->unique('user_id')->count(),
    ];
}
```

### Discrepancy Queries

```php
// Find shifts with discrepancies
$shiftsWithDiscrepancies = CashierShift::whereNotNull('discrepancy')
    ->where('discrepancy', '!=', 0)
    ->get();

// Discrepancies for a cashier
$cashierDiscrepancies = CashierShift::where('user_id', $cashierId)
    ->whereNotNull('discrepancy')
    ->where('discrepancy', '!=', 0)
    ->orderBy('discrepancy', 'desc')
    ->get();

// Discrepancy statistics
$discrepancyCount = CashierShift::where('discrepancy', '!=', 0)->count();
$avgDiscrepancy = CashierShift::avg('discrepancy');
$maxDiscrepancy = CashierShift::max('discrepancy');
$minDiscrepancy = CashierShift::min('discrepancy');

// Get discrepancy reasons
$reasons = CashierShift::whereNotNull('discrepancy_reason')
    ->groupBy('discrepancy_reason')
    ->get();
```

### Approval/Rejection Queries

```php
// Get approved shifts
$approvedShifts = CashierShift::whereNotNull('approved_at')->get();

// Get rejected shifts
$rejectedShifts = CashierShift::whereNotNull('rejected_at')->get();

// Get pending shifts (no approval or rejection)
$pendingShifts = CashierShift::whereNull('approved_at')
    ->whereNull('rejected_at')
    ->get();

// Rejection rate by cashier
$cashierId = 1;
$totalShifts = CashierShift::where('user_id', $cashierId)->count();
$rejectedShifts = CashierShift::where('user_id', $cashierId)
    ->whereNotNull('rejected_at')
    ->count();
$rejectionRate = ($rejectedShifts / $totalShifts) * 100;

// Get rejection reasons
$rejectionReasons = CashierShift::whereNotNull('rejection_reason')
    ->groupBy('rejection_reason')
    ->map(fn($group) => $group->count());

// Get who approved/rejected
$shiftApprovals = CashierShift::whereNotNull('approved_at')
    ->with('approver', 'rejecter')
    ->get();
```

---

## 3. CALCULATION PATTERNS

### Balance Calculations

```php
// Expected end saldo for UZS
$expectedEnd = $shift->calculateExpectedEndSaldo();

// Expected end saldo for any currency
$expectedUSD = $shift->getNetBalanceForCurrency(Currency::USD);

// Discrepancy calculation
$discrepancy = $shift->calculateDiscrepancy();
// Returns: counted_end_saldo - expected_end_saldo

// Check if discrepancy exists
if ($shift->hasDiscrepancy()) {
    echo "Shift has discrepancy: {$shift->discrepancy}";
}
```

### Aggregation Patterns

```php
// Total cash in (IN + IN_OUT)
$totalIn = $shift->getTotalCashInAttribute();

// Total cash out (OUT only)
$totalOut = $shift->getTotalCashOutAttribute();

// Net calculation
$net = $shift->beginning_saldo + $totalIn - $totalOut;

// Per-currency totals
$uzsTotalIn = $shift->getTotalCashInForCurrency(Currency::UZS);
$uzsTotalOut = $shift->getTotalCashOutForCurrency(Currency::UZS);

// Group and aggregate
$byType = $transactions
    ->groupBy('type')
    ->map(fn($group) => [
        'count' => $group->count(),
        'total' => $group->sum('amount'),
    ]);
```

### Time Calculations

```php
// Shift duration in hours
$hours = $shift->duration_in_hours;

// Shift duration in minutes
$minutes = $shift->opened_at->diffInMinutes($shift->closed_at ?? now());

// Peak hours (based on transaction times)
$peakHours = CashTransaction::selectRaw('HOUR(occurred_at) as hour')
    ->selectRaw('count(*) as count')
    ->groupBy('hour')
    ->orderByDesc('count')
    ->get();
```

---

## 4. FILTERING & SCOPING PATTERNS

### Authorization-Based Filtering

```php
// Get manager's accessible shifts
if ($manager->hasRole('super_admin')) {
    $shifts = CashierShift::all();
} else {
    $locationIds = $manager->locations()->pluck('id');
    $drawerIds = CashDrawer::whereIn('location_id', $locationIds)->pluck('id');
    $shifts = CashierShift::whereIn('cash_drawer_id', $drawerIds)->get();
}

// Check if manager can access specific shift
$canAccess = $manager->hasRole('super_admin') ||
    $manager->locations()->where('id', $shift->cashDrawer->location_id)->exists();
```

### Date Range Filtering

```php
// Shifts in date range
$shifts = CashierShift::whereBetween('opened_at', [$start, $end])->get();

// Transactions in date range
$transactions = CashTransaction::whereBetween('occurred_at', [$start, $end])->get();

// Today only
$today = Carbon::today();
$shifts = CashierShift::whereDate('opened_at', $today)->get();

// This month
$thisMonth = Carbon::now()->startOfMonth();
$nextMonth = Carbon::now()->addMonth()->startOfMonth();
$shifts = CashierShift::whereBetween('opened_at', [$thisMonth, $nextMonth])->get();

// This week
$week_start = Carbon::now()->startOfWeek();
$week_end = Carbon::now()->endOfWeek();
$shifts = CashierShift::whereBetween('opened_at', [$week_start, $week_end])->get();
```

---

## 5. PERFORMANCE OPTIMIZATION TIPS

### Eager Loading

```php
// DON'T: N+1 queries
$shifts = CashierShift::all();
foreach ($shifts as $shift) {
    $user = $shift->user;  // Query per shift!
}

// DO: Eager load
$shifts = CashierShift::with('user', 'cashDrawer', 'transactions')->get();
foreach ($shifts as $shift) {
    $user = $shift->user;  // Already loaded
}
```

### Selective Columns

```php
// DON'T: Load everything
$shifts = CashierShift::all();

// DO: Select only needed columns
$shifts = CashierShift::select('id', 'user_id', 'opened_at', 'status')->get();
```

### Pagination

```php
// For large datasets
$shifts = CashierShift::paginate(20);

// With relationships
$shifts = CashierShift::with('user', 'cashDrawer')
    ->paginate(20);
```

### Aggregations

```php
// DON'T: Load all and calculate in PHP
$shifts = CashierShift::all();
$total = $shifts->sum('expected_end_saldo');

// DO: Aggregate in database
$total = CashierShift::sum('expected_end_saldo');

$totals = CashierShift::select('user_id')
    ->selectRaw('sum(expected_end_saldo) as total')
    ->groupBy('user_id')
    ->get();
```

---

## 6. COMMON REPORTING QUERIES

### Dashboard Query

```php
$today = Carbon::today();
$todayShifts = CashierShift::whereDate('opened_at', $today);

$dashboard = [
    'total_shifts' => $todayShifts->count(),
    'open_shifts' => $todayShifts->where('status', ShiftStatus::OPEN)->count(),
    'closed_shifts' => $todayShifts->where('status', ShiftStatus::CLOSED)->count(),
    'total_transactions' => CashTransaction::whereIn(
        'cashier_shift_id',
        $todayShifts->pluck('id')
    )->count(),
    'total_cash_in' => CashTransaction::whereIn(
        'cashier_shift_id',
        $todayShifts->pluck('id')
    )->whereIn('type', [TransactionType::IN, TransactionType::IN_OUT])
     ->sum('amount'),
    'total_cash_out' => CashTransaction::whereIn(
        'cashier_shift_id',
        $todayShifts->pluck('id')
    )->where('type', TransactionType::OUT)
     ->sum('amount'),
    'active_cashiers' => $todayShifts
        ->with('user')
        ->get()
        ->unique('user_id')
        ->count(),
];
```

### Cashier Performance Query

```php
$cashier = User::find($cashierId);
$thisMonth = Carbon::now()->startOfMonth();
$nextMonth = Carbon::now()->addMonth()->startOfMonth();

$performance = [
    'cashier_name' => $cashier->name,
    'shifts_total' => CashierShift::where('user_id', $cashierId)
        ->whereBetween('opened_at', [$thisMonth, $nextMonth])
        ->count(),
    'transactions_total' => CashTransaction::whereIn(
        'cashier_shift_id',
        CashierShift::where('user_id', $cashierId)
            ->whereBetween('opened_at', [$thisMonth, $nextMonth])
            ->pluck('id')
    )->count(),
    'avg_shift_duration' => CashierShift::where('user_id', $cashierId)
        ->whereBetween('opened_at', [$thisMonth, $nextMonth])
        ->get()
        ->avg(fn($s) => $s->duration_in_hours),
    'discrepancies' => CashierShift::where('user_id', $cashierId)
        ->where('discrepancy', '!=', 0)
        ->whereBetween('opened_at', [$thisMonth, $nextMonth])
        ->count(),
];
```

